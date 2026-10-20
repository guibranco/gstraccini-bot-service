<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use RuntimeException;

/**
 * `github_repositories` access for both syncs, plus the pull request,
 * issue and branch reconciliation writes of the hourly repositories sync,
 * against the shared tables owned by the `webhooks` project.
 *
 * `github_repositories` holds one row per event that announced the
 * repository (and `markRepositoryRemoved()` appends one more), so it has no
 * unique key on Id. Every read here works on the **most recent row per Id**
 * (highest Sequence) and every write targets that row by Sequence. Inserts
 * and the "removed" append are delegated to CheckupRepository so the syncs
 * write exactly the rows the checkup job and the installations handler do.
 *
 * LastSyncDate was added by webhooks migration Sql/0041; see its ADR-0011
 * for what the date means.
 */
class RepositoriesSyncRepository extends SyncRepositoryBase
{
    /**
     * Tables (and their owner/name columns) that reference a repository by
     * owner and name. Mirrors the list the webhooks receiver rewrites on a
     * `renamed`/`transferred` event, so a rename the sync detects is applied
     * the same way a delivered event would have applied it.
     *
     * @var array<string, array{0: string, 1: string}> table => [owner column, name column]
     */
    private const REPOSITORY_REFERENCE_TABLES = [
        "github" => ["Owner", "Repository"],
        "github_branches" => ["RepositoryOwner", "RepositoryName"],
        "github_comments" => ["RepositoryOwner", "RepositoryName"],
        "github_issues" => ["RepositoryOwner", "RepositoryName"],
        "github_pull_requests" => ["RepositoryOwner", "RepositoryName"],
        "github_pushes" => ["RepositoryOwner", "RepositoryName"],
        "github_warnings" => ["RepositoryOwner", "RepositoryName"],
        "github_workflow_runs" => ["RepositoryOwner", "RepositoryName"],
    ];

    private const REPOSITORY_COLUMNS = "Sequence, Id, NodeId, Name, FullName, Private, OwnerLogin, OwnerId, OwnerNodeId, InstallationId, LastAction, InstallationAction, LastSyncDate";

    private const LATEST_REPOSITORY_COLUMNS = "r.Sequence, r.Id, r.NodeId, r.Name, r.FullName, r.Private, r.OwnerLogin, r.OwnerId, r.OwnerNodeId, r.InstallationId, r.LastAction, r.InstallationAction, r.LastSyncDate";

    private const LATEST_REPOSITORY_JOIN = "INNER JOIN (SELECT Id, MAX(Sequence) AS MaxSequence FROM github_repositories GROUP BY Id) latest ON latest.Id = r.Id AND latest.MaxSequence = r.Sequence";

    private CheckupRepository $checkupRepository;

    public function __construct(?CheckupRepository $checkupRepository = null)
    {
        $this->checkupRepository = $checkupRepository ?? new CheckupRepository();
    }

    // ---------------------------------------------------------------- repositories

    /**
     * The most recent `github_repositories` row of a repository, whatever
     * its state, or null when the repository was never registered.
     */
    public function getLatestRepositoryById(int $repositoryId): ?object
    {
        $mysqli = connectToDatabase();
        $sql = "SELECT " . self::REPOSITORY_COLUMNS . " FROM github_repositories WHERE Id = ? ORDER BY Sequence DESC LIMIT 1";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $repositoryId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_object();
        $stmt->close();
        $mysqli->close();

        return $row ?: null;
    }

    /**
     * Ids of the repositories currently registered as active for an
     * installation: the most recent row per repository (across every
     * installation, so a repository transferred to another installation
     * isn't counted twice) belongs to this installation and isn't `deleted`.
     *
     * @return array<int>
     */
    public function getActiveRepositoryIdsForInstallation(int $installationId): array
    {
        $mysqli = connectToDatabase();
        $sql = "SELECT r.Id FROM github_repositories r " . self::LATEST_REPOSITORY_JOIN . "
                WHERE r.InstallationId = ? AND r.LastAction != 'deleted'";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $installationId);
        $stmt->execute();
        $result = $stmt->get_result();

        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row["Id"];
        }

        $stmt->close();
        $mysqli->close();
        return $ids;
    }

    /**
     * Registers a repository GitHub lists for an installation that has no
     * active row, exactly like the checkup job and the installations
     * handler do (InstallationAction 'added', ProcessingState NEW, so the
     * repositories handler onboards it as if an event had announced it).
     */
    public function insertRepository(object $repository, int $installationId, string $webhooksHandlerVersion): void
    {
        $this->checkupRepository->insertRepository($repository, $installationId, $webhooksHandlerVersion);
    }

    /**
     * Appends the `deleted`/`removed` row that records a repository is no
     * longer accessible, as the installations handler does.
     */
    public function markRepositoryRemoved(int $repositoryId, int $installationId, string $webhooksHandlerVersion): void
    {
        $this->checkupRepository->markRepositoryRemoved($repositoryId, $installationId, $webhooksHandlerVersion);
    }

    /**
     * Writes the metadata columns (see RepositoryMetadata::FIELDS) and the
     * installation id onto a repository row. Callers compare first with
     * RepositoryMetadata::changes() and only call this when something
     * differs, so UpdatedAt moves only for a real change.
     *
     * @param array<string, int|string> $metadata From RepositoryMetadata::fromGitHub().
     */
    public function updateRepositoryMetadata(int $sequence, array $metadata, int $installationId): void
    {
        $mysqli = connectToDatabase();
        $sql = "UPDATE github_repositories
                SET NodeId = ?, Name = ?, FullName = ?, Private = ?, OwnerLogin = ?, OwnerId = ?, OwnerNodeId = ?, InstallationId = ?
                WHERE Sequence = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(
            "sssisisii",
            $metadata["NodeId"],
            $metadata["Name"],
            $metadata["FullName"],
            $metadata["Private"],
            $metadata["OwnerLogin"],
            $metadata["OwnerId"],
            $metadata["OwnerNodeId"],
            $installationId,
            $sequence
        );
        $this->executeAndClose($stmt, $mysqli);
    }

    /**
     * Follows a repository rename or transfer in every table that references
     * the repository by owner and name — the same tables the webhooks
     * receiver rewrites on a `renamed`/`transferred` event — so the pull
     * requests, issues and branches already stored keep matching what
     * GitHub lists under the new name. Each table is updated on its own and
     * a failure in one (e.g. a unique key already taken by rows written under
     * the new name) is logged and doesn't stop the others.
     *
     * @return array<string, int> Rows changed per table (-1 when that table's update failed).
     */
    public function renameRepositoryReferences(string $oldOwner, string $oldName, string $newOwner, string $newName): array
    {
        global $logger;

        $mysqli = connectToDatabase();
        $changed = [];

        foreach (self::REPOSITORY_REFERENCE_TABLES as $table => [$ownerColumn, $nameColumn]) {
            $sql = "UPDATE `{$table}` SET `{$ownerColumn}` = ?, `{$nameColumn}` = ? WHERE `{$ownerColumn}` = ? AND `{$nameColumn}` = ?";
            $stmt = $mysqli->prepare($sql);

            if ($stmt === false) {
                $changed[$table] = -1;
                $logger->log("Failed to prepare rename of {$oldOwner}/{$oldName} to {$newOwner}/{$newName} in {$table}", $mysqli->error);
                continue;
            }

            $stmt->bind_param("ssss", $newOwner, $newName, $oldOwner, $oldName);

            try {
                if (!$stmt->execute()) {
                    throw new RuntimeException("[" . $stmt->errno . "] " . $stmt->error);
                }
                $changed[$table] = $stmt->affected_rows;
            } catch (\Throwable $e) {
                $changed[$table] = -1;
                $logger->log("Failed to rename {$oldOwner}/{$oldName} to {$newOwner}/{$newName} in {$table}: {$e->getMessage()}", null);
            } finally {
                $stmt->close();
            }
        }

        $mysqli->close();
        return $changed;
    }

    // ---------------------------------------------------------------- hourly queue

    /**
     * Picks the active repository that has gone longest without a sync
     * (LastSyncDate NULL first, then oldest) and claims it by stamping
     * LastSyncDate = NOW() — before any GitHub call, so a repository whose
     * sync then fails goes to the back of the queue instead of being picked
     * again next hour. The claim is conditional on the date not having moved
     * since the pick, which keeps two overlapping runs from taking the same
     * repository; on a lost race the pick is simply repeated.
     *
     * @param bool $claim False to only look (dry run): nothing is written.
     *
     * @return object|null The repository row (as it was before the claim), or null when there is nothing to sync.
     */
    public function claimNextRepositoryToSync(bool $claim = true): ?object
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $repository = $this->pickNextRepositoryToSync();

            if ($repository === null || !$claim) {
                return $repository;
            }

            if ($this->claimRepository((int) $repository->Sequence, $repository->LastSyncDate)) {
                return $repository;
            }
        }

        return null;
    }

    /**
     * Claims one specific repository (its most recent row, as long as it is
     * active) regardless of its place in the queue.
     *
     * @param bool $claim False to only look (dry run): nothing is written.
     */
    public function claimRepositoryToSync(int $repositoryId, bool $claim = true): ?object
    {
        $repository = $this->getLatestRepositoryById($repositoryId);
        if ($repository === null || $repository->LastAction === "deleted") {
            return null;
        }

        if ($claim && !$this->claimRepository((int) $repository->Sequence, $repository->LastSyncDate)) {
            return null;
        }

        return $repository;
    }

    /**
     * Seconds elapsed since the newest LastSyncDate in `github_repositories`,
     * i.e. since the repositories sync last claimed a repository, or null
     * when it never has. Computed by the database so the PHP and database
     * clocks' time zones never enter the comparison; it is what paces the
     * hourly sync across processes and restarts.
     */
    public function getSecondsSinceLastRepositoryClaim(): ?int
    {
        $mysqli = connectToDatabase();
        $result = $this->queryOrFail($mysqli, "SELECT TIMESTAMPDIFF(SECOND, MAX(LastSyncDate), NOW()) AS Seconds FROM github_repositories");

        $row = $result->fetch_assoc();
        $result->close();
        $mysqli->close();

        return $row === null || $row["Seconds"] === null ? null : (int) $row["Seconds"];
    }

    private function pickNextRepositoryToSync(): ?object
    {
        $mysqli = connectToDatabase();
        $sql = "SELECT " . self::LATEST_REPOSITORY_COLUMNS . "
                FROM github_repositories r " . self::LATEST_REPOSITORY_JOIN . "
                WHERE r.LastAction != 'deleted'
                ORDER BY (r.LastSyncDate IS NULL) DESC, r.LastSyncDate ASC, r.Sequence ASC
                LIMIT 1";

        $result = $this->queryOrFail($mysqli, $sql);
        $repository = $result->fetch_object();
        $result->close();
        $mysqli->close();

        return $repository ?: null;
    }

    private function claimRepository(int $sequence, ?string $lastSyncDate): bool
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("UPDATE github_repositories SET LastSyncDate = NOW(), UpdatedAt = UpdatedAt WHERE Sequence = ? AND LastSyncDate <=> ?");
        $stmt->bind_param("is", $sequence, $lastSyncDate);

        try {
            if (!$stmt->execute()) {
                throw new RuntimeException("Execute failed: [" . $stmt->errno . "] " . $stmt->error);
            }

            // affected_rows counts changed rows, so a claim within the same second as the previous
            // one reports 0 even though the row matched; the row count in `info` tells matched from changed.
            return $stmt->affected_rows > 0 || preg_match('/Rows matched: ([1-9]\d*)/', (string) $mysqli->info) === 1;
        } finally {
            $stmt->close();
            $mysqli->close();
        }
    }

    // ---------------------------------------------------------------- pull requests, issues, branches

    /**
     * @return array<int, object> Sequence, Number and ProcessingState of every pull request stored as OPEN for the repository.
     */
    public function getOpenPullRequests(string $owner, string $name): array
    {
        return $this->fetchOpenItems("github_pull_requests", $owner, $name);
    }

    /**
     * @return array<int, object> Sequence, Number and ProcessingState of every issue stored as OPEN for the repository.
     */
    public function getOpenIssues(string $owner, string $name): array
    {
        return $this->fetchOpenItems("github_issues", $owner, $name);
    }

    /**
     * Refs of the branches currently registered for the repository (created
     * and not since deleted, excluding the default branch), as the branches
     * view reports them.
     *
     * @return array<int, string>
     */
    public function getBranchRefs(string $owner, string $name): array
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("SELECT Ref FROM github_branches_view WHERE RepositoryOwner = ? AND RepositoryName = ?");
        $stmt->bind_param("ss", $owner, $name);
        $stmt->execute();
        $result = $stmt->get_result();

        $refs = [];
        while ($row = $result->fetch_assoc()) {
            $refs[] = (string) $row["Ref"];
        }

        $stmt->close();
        $mysqli->close();
        return array_values(array_unique($refs));
    }

    /**
     * Hands a pull request GitHub no longer lists as open back to the pull
     * requests handler, the way an event would (`RE_REQUESTED`, with the idle
     * retry count reset as every event-driven write does). The handler then
     * fetches it and runs its normal closing flow. Only rows the bot has
     * finished with are touched, so an in-flight processing isn't disturbed.
     *
     * @return bool True when the row was re-requested.
     */
    public function reRequestPullRequest(int $sequence): bool
    {
        $mysqli = connectToDatabase();
        $sql = "UPDATE github_pull_requests
                SET ProcessingState = 'RE_REQUESTED', ProcessingDate = NULL, IdleRetryCount = 0
                WHERE Sequence = ? AND State = 'OPEN' AND ProcessingState = 'PROCESSED'";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $sequence);

        return $this->executeAndClose($stmt, $mysqli) > 0;
    }

    /**
     * Hands an issue GitHub no longer lists as open back to the issues
     * handler (`RE_REQUESTED`), which fetches it and closes it in the database.
     *
     * @return bool True when the row was re-requested.
     */
    public function reRequestIssue(int $sequence): bool
    {
        $mysqli = connectToDatabase();
        $sql = "UPDATE github_issues
                SET ProcessingState = 'RE_REQUESTED', ProcessingDate = NULL
                WHERE Sequence = ? AND State = 'OPEN' AND ProcessingState = 'PROCESSED'";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $sequence);

        return $this->executeAndClose($stmt, $mysqli) > 0;
    }

    /**
     * Records that a branch GitHub no longer has was deleted — the same
     * write the webhooks receiver does for a `delete` event, which also
     * queues the row for the branches handler (`UPDATED`).
     *
     * @return int Rows changed.
     */
    public function markBranchDeleted(string $owner, string $name, string $ref): int
    {
        $mysqli = connectToDatabase();
        $sql = "UPDATE github_branches
                SET Event = 'delete', ProcessingState = 'UPDATED', ProcessedDate = NULL
                WHERE Ref = ? AND RepositoryOwner = ? AND RepositoryName = ? AND Event = 'create'";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sss", $ref, $owner, $name);

        return $this->executeAndClose($stmt, $mysqli);
    }

    /**
     * @return array<int, object>
     */
    private function fetchOpenItems(string $table, string $owner, string $name): array
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("SELECT Sequence, Number, ProcessingState FROM `{$table}` WHERE RepositoryOwner = ? AND RepositoryName = ? AND State = 'OPEN' ORDER BY Number ASC");
        $stmt->bind_param("ss", $owner, $name);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_object()) {
            $items[] = $row;
        }

        $stmt->close();
        $mysqli->close();
        return $items;
    }
}
