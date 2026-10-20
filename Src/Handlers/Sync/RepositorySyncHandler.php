<?php

namespace GuiBranco\GStracciniBot\Handlers\Sync;

use GuiBranco\GStracciniBot\Handlers\Checkup\BranchesCheckupHandler;
use GuiBranco\GStracciniBot\Handlers\Checkup\IssuesCheckupHandler;
use GuiBranco\GStracciniBot\Handlers\Checkup\PullRequestsCheckupHandler;
use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;
use GuiBranco\GStracciniBot\Library\Sync\IntervalSchedule;
use GuiBranco\GStracciniBot\Library\Sync\RepositoriesSyncRepository;
use GuiBranco\GStracciniBot\Library\Sync\RepositoryMetadata;
use GuiBranco\GStracciniBot\Library\Sync\ScheduledJob;
use RuntimeException;
use Throwable;

/**
 * The hourly repositories sync: takes the active repository that has gone
 * longest without a sync (LastSyncDate NULL first, then oldest), stamps its
 * LastSyncDate, and reconciles it with GitHub.
 *
 * It runs from the repositories worker's own loop (`scheduledJob()`,
 * registered by Src/repositories.php and Src/Workers/repositories.php),
 * one repository every SCHEDULE_INTERVAL_SECONDS, paced by the newest
 * LastSyncDate in the table — so the cadence holds across processes and
 * restarts. Src/Workers/syncRepositories.php runs it by hand.
 *
 * What a sync does:
 *
 * 1. Metadata — `GET /repositories/{id}` (by id, so a renamed or transferred
 *    repository is still found): name, full name, visibility, owner and node
 *    id are updated on the row; a rename/transfer is followed into the other
 *    tables that reference the repository by owner/name.
 * 2. Pull requests — open pull requests missing from `github_pull_requests`
 *    are backfilled (PullRequestsCheckupHandler); ones stored as OPEN that
 *    GitHub no longer lists as open are handed back to the pull requests
 *    handler (`RE_REQUESTED`), which fetches them and runs the normal closing
 *    flow (labels, notifications, next pull request) — this worker never
 *    closes a row itself.
 * 3. Issues — same as pull requests, with IssuesCheckupHandler and the
 *    issues handler.
 * 4. Branches — branches missing from `github_branches` are backfilled
 *    (BranchesCheckupHandler); ones GitHub no longer has get the `delete`
 *    event's write, which queues them for the branches handler.
 *
 * A repository whose installation no longer exists (token request 404), or
 * that the installation can no longer access (token request 422, or the
 * repository itself 404), gets the `deleted`/`removed` row the installations
 * handler appends and drops out of the queue. Any other failure is logged
 * and counted; since the LastSyncDate claim already happened, the repository
 * simply moves to the back of the queue.
 *
 * Listings are strict: a failed page aborts that repository's sync instead
 * of re-requesting pull requests or deleting branches on a partial list.
 */
class RepositorySyncHandler
{
    /** Seconds between two scheduled runs (one repository each). */
    public const SCHEDULE_INTERVAL_SECONDS = 3600;

    /** Seconds between GitHub requests when the sync runs from the worker. */
    public const SCHEDULED_THROTTLE_SECONDS = 1;

    private const LOG_CHANNEL = "sync_repositories";

    private RepositoriesSyncRepository $repository;
    private GitHubCollectionFetcher $fetcher;
    private PullRequestsCheckupHandler $pullRequestsHandler;
    private IssuesCheckupHandler $issuesHandler;
    private BranchesCheckupHandler $branchesHandler;
    private bool $dryRun;
    private string $webhooksHandlerVersion;

    /** @var array<string, array<string, int>> */
    private array $report = [];

    public function __construct(
        int $throttleSeconds,
        bool $dryRun,
        string $version,
        ?RepositoriesSyncRepository $repository = null,
        ?CheckupRepository $checkupRepository = null,
        ?GitHubCollectionFetcher $fetcher = null
    ) {
        $checkupRepository = $checkupRepository ?? new CheckupRepository();

        $this->repository = $repository ?? new RepositoriesSyncRepository($checkupRepository);
        $this->fetcher = $fetcher ?? new GitHubCollectionFetcher($throttleSeconds, true);
        $this->dryRun = $dryRun;
        $this->webhooksHandlerVersion = self::LOG_CHANNEL . ":" . $version;

        $this->pullRequestsHandler = new PullRequestsCheckupHandler($checkupRepository, $this->fetcher, $dryRun, $this->webhooksHandlerVersion);
        $this->issuesHandler = new IssuesCheckupHandler($checkupRepository, $this->fetcher, $dryRun, $this->webhooksHandlerVersion);
        $this->branchesHandler = new BranchesCheckupHandler($checkupRepository, $this->fetcher, $dryRun, $this->webhooksHandlerVersion);
    }

    /**
     * The sync as a job for the repositories worker's loop
     * (ProcessingManager::addScheduledJob()): one repository per
     * SCHEDULE_INTERVAL_SECONDS, counted from the last claim recorded in
     * `github_repositories.LastSyncDate`. A new handler is built for every
     * run so nothing is carried over between repositories.
     *
     * @param string              $version    The bot version, stamped on the rows the sync writes.
     * @param RepositoriesSyncRepository|null $repository Database access (shared with the run for the pacing query).
     */
    public static function scheduledJob(string $version, ?RepositoriesSyncRepository $repository = null): ScheduledJob
    {
        $repository = $repository ?? new RepositoriesSyncRepository();
        $schedule = new IntervalSchedule(
            self::SCHEDULE_INTERVAL_SECONDS,
            static fn(): ?int => $repository->getSecondsSinceLastRepositoryClaim()
        );

        return new ScheduledJob(
            self::LOG_CHANNEL,
            $schedule,
            static function () use ($version, $repository): void {
                $handler = new self(self::SCHEDULED_THROTTLE_SECONDS, false, $version, $repository);
                $handler->run();
            }
        );
    }

    /**
     * @param int|null $onlyRepositoryId If set, syncs this repository (by GitHub id) instead of the queue's next one.
     * @param int      $limit            How many repositories to take from the queue in this run (default 1).
     *
     * @return array<string, array<string, int>> The run's counters, per category.
     */
    public function run(?int $onlyRepositoryId = null, int $limit = 1): array
    {
        global $logStream;

        $this->report = [
            "repositories" => ["claimed" => 0, "updated" => 0, "renamed" => 0, "removed" => 0, "failed" => 0],
            "pullRequests" => ["checked" => 0, "backfilled" => 0, "reRequested" => 0],
            "issues" => ["checked" => 0, "backfilled" => 0, "reRequested" => 0],
            "branches" => ["checked" => 0, "backfilled" => 0, "deleted" => 0],
        ];

        echo "==> Repositories sync started" . ($this->dryRun ? " (dry run)" : "") . "\n";
        $logStream?->info(
            "Repositories sync started",
            ["dryRun" => $this->dryRun, "repositoryId" => $onlyRepositoryId, "limit" => $limit],
            self::LOG_CHANNEL
        );

        if ($onlyRepositoryId !== null) {
            $repository = $this->repository->claimRepositoryToSync($onlyRepositoryId, !$this->dryRun);
            if ($repository === null) {
                echo "Repository #{$onlyRepositoryId} is not registered as active (or another run just claimed it) — nothing to sync\n";
            } else {
                $this->syncRepository($repository);
            }
        } else {
            for ($taken = 0; $taken < max(1, $limit); $taken++) {
                $repository = $this->repository->claimNextRepositoryToSync(!$this->dryRun);
                if ($repository === null) {
                    echo "No active repositories to sync\n";
                    break;
                }

                $this->syncRepository($repository);

                if ($this->dryRun) {
                    // Without a claim the queue's head doesn't move, so a dry run can only preview the next one.
                    break;
                }
            }
        }

        echo "==> Repositories sync finished\n";
        $this->printSummary();
        $logStream?->info("Repositories sync finished", $this->report, self::LOG_CHANNEL);

        return $this->report;
    }

    private function syncRepository(object $row): void
    {
        global $logStream;

        $lastSynced = $row->LastSyncDate ?? "never";
        echo "Repository: {$row->FullName} (#{$row->Id}) — installation #{$row->InstallationId}, last synced: {$lastSynced}\n";
        $logStream?->info(
            "Syncing repository {$row->FullName}",
            ["repo" => $row->FullName, "repositoryId" => (int) $row->Id, "installationId" => (int) $row->InstallationId, "lastSyncDate" => $row->LastSyncDate],
            self::LOG_CHANNEL
        );
        $this->report["repositories"]["claimed"]++;

        try {
            $this->reconcile($row);
        } catch (Throwable $e) {
            echo "  Sync failed: {$e->getMessage()} ❌\n";
            $logStream?->error(
                "Repository sync failed for {$row->FullName}: {$e->getMessage()}",
                ["repo" => $row->FullName, "repositoryId" => (int) $row->Id, "file" => $e->getFile(), "line" => $e->getLine()],
                self::LOG_CHANNEL
            );
            $this->report["repositories"]["failed"]++;
        }
    }

    private function reconcile(object $row): void
    {
        $repositoryId = (int) $row->Id;
        $installationId = (int) $row->InstallationId;

        $tokenResponse = requestInstallationAccessToken((string) $installationId, [$repositoryId]);
        $tokenStatus = $tokenResponse->getStatusCode();
        if ($tokenStatus === 404) {
            $this->markRemoved($row, "Installation #{$installationId} no longer exists");
            return;
        }
        if ($tokenStatus === 422) {
            $this->markRemoved($row, "No longer accessible to installation #{$installationId}");
            return;
        }
        if (!$tokenResponse->isSuccessStatusCode()) {
            throw new RuntimeException(
                "Could not create an access token for repository #{$repositoryId} (installation #{$installationId}): " .
                "HTTP status {$tokenStatus}. Body: " . ($tokenResponse->getBody() ?? $tokenResponse->getMessage())
            );
        }
        $token = decodeGitHubResponse($tokenResponse, "token");

        $response = doRequestGitHub($token, "repositories/{$repositoryId}", null, "GET");
        $this->fetcher->pace($response);
        if ($response->getStatusCode() === 404) {
            $this->markRemoved($row, "GitHub no longer reports repository #{$repositoryId}");
            return;
        }
        if (!$response->isSuccessStatusCode()) {
            throw new RuntimeException(
                "Could not fetch repository #{$repositoryId}: HTTP status {$response->getStatusCode()}. " .
                "Body: " . ($response->getBody() ?? $response->getMessage())
            );
        }

        $repository = json_decode($response->getBody());
        if (!is_object($repository) || !isset($repository->id, $repository->owner)) {
            throw new RuntimeException("Unexpected GitHub API response for repository #{$repositoryId}: " . $response->getBody());
        }

        $metadata = $this->syncMetadata($row, $repository, $installationId);

        if (!empty($repository->archived)) {
            echo "  Archived on GitHub 📦 — contents are read-only there, still reconciling\n";
        }

        $context = (object) [
            "id" => $repositoryId,
            "owner" => $metadata["OwnerLogin"],
            "ownerId" => $metadata["OwnerId"],
            "ownerNodeId" => $metadata["OwnerNodeId"],
            "name" => $metadata["Name"],
            "defaultBranch" => (string) ($repository->default_branch ?? "main"),
            "installationId" => $installationId,
            "token" => $token,
        ];

        $this->syncPullRequests($context);
        $this->syncIssues($context);
        $this->syncBranches($context);
    }

    /**
     * @return array<string, int|string> The metadata now stored (from RepositoryMetadata::fromGitHub()).
     */
    private function syncMetadata(object $row, object $repository, int $installationId): array
    {
        $metadata = RepositoryMetadata::fromGitHub($repository);
        $changes = RepositoryMetadata::changes($row, $metadata);

        if (RepositoryMetadata::isRenamed($changes)) {
            echo "  Renamed/transferred: {$row->FullName} → {$repository->full_name} — updating references 🔁\n";
            if (!$this->dryRun) {
                $this->repository->renameRepositoryReferences(
                    (string) $row->OwnerLogin,
                    (string) $row->Name,
                    $metadata["OwnerLogin"],
                    $metadata["Name"]
                );
            }
            $this->report["repositories"]["renamed"]++;
        }

        if (empty($changes)) {
            echo "  Metadata up to date\n";
            return $metadata;
        }

        echo "  Metadata: updating " . implode(", ", array_keys($changes)) . " 📝\n";
        if (!$this->dryRun) {
            $this->repository->updateRepositoryMetadata((int) $row->Sequence, $metadata, $installationId);
        }
        $this->report["repositories"]["updated"]++;

        return $metadata;
    }

    private function syncPullRequests(object $context): void
    {
        echo "  Pull requests:\n";
        $result = $this->pullRequestsHandler->check($context);
        $this->accumulate("pullRequests", $result);

        $openNumbers = array_map(static fn(object $pullRequest): int => (int) $pullRequest->number, $result["contexts"]);

        foreach ($this->repository->getOpenPullRequests($context->owner, $context->name) as $stored) {
            $number = (int) $stored->Number;
            if (in_array($number, $openNumbers, true)) {
                continue;
            }

            if ($stored->ProcessingState !== "PROCESSED") {
                echo "    PR #{$number} is not open on GitHub but is still queued ({$stored->ProcessingState}) — the handler will close it\n";
                continue;
            }

            echo "    PR #{$number} is not open on GitHub any more — re-requesting processing 🔄\n";
            if (!$this->dryRun) {
                $this->repository->reRequestPullRequest((int) $stored->Sequence);
            }
            $this->report["pullRequests"]["reRequested"]++;
        }

        if (empty($openNumbers)) {
            echo "    No open pull requests on GitHub\n";
        }
    }

    private function syncIssues(object $context): void
    {
        echo "  Issues:\n";
        $result = $this->issuesHandler->check($context);
        $this->accumulate("issues", $result);

        $openNumbers = array_map(static fn(object $issue): int => (int) $issue->number, $result["contexts"]);

        foreach ($this->repository->getOpenIssues($context->owner, $context->name) as $stored) {
            $number = (int) $stored->Number;
            if (in_array($number, $openNumbers, true)) {
                continue;
            }

            if ($stored->ProcessingState !== "PROCESSED") {
                echo "    Issue #{$number} is not open on GitHub but is still queued ({$stored->ProcessingState}) — the handler will close it\n";
                continue;
            }

            echo "    Issue #{$number} is not open on GitHub any more — re-requesting processing 🔄\n";
            if (!$this->dryRun) {
                $this->repository->reRequestIssue((int) $stored->Sequence);
            }
            $this->report["issues"]["reRequested"]++;
        }

        if (empty($openNumbers)) {
            echo "    No open issues on GitHub\n";
        }
    }

    private function syncBranches(object $context): void
    {
        echo "  Branches:\n";
        $result = $this->branchesHandler->check($context);
        $this->accumulate("branches", $result);

        $refs = array_map(static fn(object $branch): string => (string) $branch->ref, $result["contexts"]);

        foreach ($this->repository->getBranchRefs($context->owner, $context->name) as $ref) {
            if ($ref === $context->defaultBranch || in_array($ref, $refs, true)) {
                continue;
            }

            echo "    Branch {$ref} no longer exists on GitHub — recording its deletion 🗑️\n";
            if (!$this->dryRun) {
                $this->repository->markBranchDeleted($context->owner, $context->name, $ref);
            }
            $this->report["branches"]["deleted"]++;
        }

        if (empty($refs)) {
            echo "    No branches besides {$context->defaultBranch} on GitHub\n";
        }
    }

    private function markRemoved(object $row, string $reason): void
    {
        echo "  {$reason} — marking removed ⚠️\n";
        if (!$this->dryRun) {
            $this->repository->markRepositoryRemoved((int) $row->Id, (int) $row->InstallationId, $this->webhooksHandlerVersion);
        }
        $this->report["repositories"]["removed"]++;
    }

    /**
     * @param array{checked: int, backfilled: int} $result
     */
    private function accumulate(string $category, array $result): void
    {
        $this->report[$category]["checked"] += $result["checked"];
        $this->report[$category]["backfilled"] += $result["backfilled"];
    }

    private function printSummary(): void
    {
        echo "\n===== Repositories sync summary" . ($this->dryRun ? " (dry run — nothing was written)" : "") . " =====\n";
        foreach ($this->report as $category => $counters) {
            $parts = [];
            foreach ($counters as $name => $value) {
                $parts[] = "{$name}: {$value}";
            }
            echo str_pad($category, 16) . implode("  ", $parts) . "\n";
        }
    }
}
