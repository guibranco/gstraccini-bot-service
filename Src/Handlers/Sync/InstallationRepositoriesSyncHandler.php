<?php

namespace GuiBranco\GStracciniBot\Handlers\Sync;

use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;
use GuiBranco\GStracciniBot\Library\Sync\RepositoriesSyncRepository;
use GuiBranco\GStracciniBot\Library\Sync\RepositoryMetadata;
use RuntimeException;

/**
 * Reconciles `github_repositories` with what one installation can access
 * (`GET /installation/repositories`), for the monthly installations sync:
 *
 * - a listed repository with no active row is added (ProcessingState NEW,
 *   so the repositories handler onboards it like a delivered event);
 * - a listed repository whose row differs gets its metadata updated (name,
 *   full name, visibility, owner, node id, installation), and a rename or
 *   transfer is followed into the other tables that reference it by
 *   owner/name, as the webhooks receiver does for a `renamed` event;
 * - an active row the installation no longer lists gets the
 *   `deleted`/`removed` row the installations handler appends.
 *
 * The listing is strict: a failed page aborts the whole pass with an
 * exception rather than marking repositories removed on a partial list.
 */
class InstallationRepositoriesSyncHandler
{
    private RepositoriesSyncRepository $repository;
    private GitHubCollectionFetcher $fetcher;
    private bool $dryRun;
    private string $webhooksHandlerVersion;

    /** @var array<string, int> */
    private array $report = [];

    public function __construct(
        RepositoriesSyncRepository $repository,
        GitHubCollectionFetcher $fetcher,
        bool $dryRun,
        string $webhooksHandlerVersion
    ) {
        $this->repository = $repository;
        $this->fetcher = $fetcher;
        $this->dryRun = $dryRun;
        $this->webhooksHandlerVersion = $webhooksHandlerVersion;
    }

    /**
     * @return array{checked: int, added: int, updated: int, renamed: int, removed: int}
     *
     * @throws RuntimeException When no access token can be created for the installation
     *                          (suspended, uninstalled) or a listing page fails.
     */
    public function sync(int $installationId, string $accountLogin): array
    {
        $tokenResponse = requestInstallationAccessToken((string) $installationId);
        if (!$tokenResponse->isSuccessStatusCode()) {
            throw new RuntimeException(
                "Could not create an access token for installation {$accountLogin} (#{$installationId}): " .
                "HTTP status {$tokenResponse->getStatusCode()}. Body: " . ($tokenResponse->getBody() ?? $tokenResponse->getMessage())
            );
        }

        $token = decodeGitHubResponse($tokenResponse, "token");
        $repositories = $this->fetcher->fetchAllPages($token, "installation/repositories", "repositories");

        $this->report = ["checked" => 0, "added" => 0, "updated" => 0, "renamed" => 0, "removed" => 0];
        $listedIds = [];

        foreach ($repositories as $repository) {
            $this->report["checked"]++;
            $listedIds[] = (int) $repository->id;
            $this->reconcileListedRepository($repository, $installationId);
        }

        $this->removeUnlistedRepositories($installationId, $listedIds);

        return $this->report;
    }

    private function reconcileListedRepository(object $repository, int $installationId): void
    {
        $latest = $this->repository->getLatestRepositoryById((int) $repository->id);

        if ($latest === null || $latest->LastAction === "deleted") {
            $reason = $latest === null ? "not registered" : "registered as deleted/removed";
            echo "  Repository: {$repository->full_name} — {$reason}, adding ✅\n";
            if (!$this->dryRun) {
                $this->repository->insertRepository($repository, $installationId, $this->webhooksHandlerVersion);
            }
            $this->report["added"]++;
            return;
        }

        $metadata = RepositoryMetadata::fromGitHub($repository);
        $changes = RepositoryMetadata::changes($latest, $metadata);
        $installationChanged = (int) $latest->InstallationId !== $installationId;

        if (empty($changes) && !$installationChanged) {
            echo "  Repository: {$repository->full_name} — up to date\n";
            return;
        }

        if (RepositoryMetadata::isRenamed($changes)) {
            echo "  Repository: {$latest->FullName} → {$repository->full_name} — renamed/transferred, updating references 🔁\n";
            if (!$this->dryRun) {
                $this->repository->renameRepositoryReferences(
                    (string) $latest->OwnerLogin,
                    (string) $latest->Name,
                    $metadata["OwnerLogin"],
                    $metadata["Name"]
                );
            }
            $this->report["renamed"]++;
        }

        $changedColumns = array_keys($changes);
        if ($installationChanged) {
            $changedColumns[] = "InstallationId";
        }
        echo "  Repository: {$repository->full_name} — updating " . implode(", ", $changedColumns) . " 📝\n";
        if (!$this->dryRun) {
            $this->repository->updateRepositoryMetadata((int) $latest->Sequence, $metadata, $installationId);
        }
        $this->report["updated"]++;
    }

    /**
     * @param array<int> $listedIds Ids of every repository GitHub listed for the installation.
     */
    private function removeUnlistedRepositories(int $installationId, array $listedIds): void
    {
        $staleIds = array_diff($this->repository->getActiveRepositoryIdsForInstallation($installationId), $listedIds);

        foreach ($staleIds as $staleId) {
            echo "  Repository #{$staleId} — no longer accessible to the installation, marking removed ⚠️\n";
            if (!$this->dryRun) {
                $this->repository->markRepositoryRemoved($staleId, $installationId, $this->webhooksHandlerVersion);
            }
            $this->report["removed"]++;
        }
    }
}
