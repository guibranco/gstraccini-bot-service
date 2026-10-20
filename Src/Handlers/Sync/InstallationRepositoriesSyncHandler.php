<?php

namespace GuiBranco\GStracciniBot\Handlers\Sync;

use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;
use GuiBranco\GStracciniBot\Library\Sync\RepositoryMetadata;
use GuiBranco\GStracciniBot\Library\Sync\SyncRepository;
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
    private SyncRepository $repository;
    private GitHubCollectionFetcher $fetcher;
    private bool $dryRun;
    private string $webhooksHandlerVersion;

    public function __construct(
        SyncRepository $repository,
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

        $report = ["checked" => 0, "added" => 0, "updated" => 0, "renamed" => 0, "removed" => 0];
        $listedIds = [];

        foreach ($repositories as $repository) {
            $report["checked"]++;
            $repositoryId = (int) $repository->id;
            $listedIds[] = $repositoryId;

            $latest = $this->repository->getLatestRepositoryById($repositoryId);

            if ($latest === null || $latest->LastAction === "deleted") {
                $reason = $latest === null ? "not registered" : "registered as deleted/removed";
                echo "  Repository: {$repository->full_name} — {$reason}, adding ✅\n";
                if (!$this->dryRun) {
                    $this->repository->insertRepository($repository, $installationId, $this->webhooksHandlerVersion);
                }
                $report["added"]++;
                continue;
            }

            $metadata = RepositoryMetadata::fromGitHub($repository);
            $changes = RepositoryMetadata::changes($latest, $metadata);
            $installationChanged = (int) $latest->InstallationId !== $installationId;

            if (empty($changes) && !$installationChanged) {
                echo "  Repository: {$repository->full_name} — up to date\n";
                continue;
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
                $report["renamed"]++;
            }

            $changedColumns = array_keys($changes);
            if ($installationChanged) {
                $changedColumns[] = "InstallationId";
            }
            echo "  Repository: {$repository->full_name} — updating " . implode(", ", $changedColumns) . " 📝\n";
            if (!$this->dryRun) {
                $this->repository->updateRepositoryMetadata((int) $latest->Sequence, $metadata, $installationId);
            }
            $report["updated"]++;
        }

        $staleIds = array_diff($this->repository->getActiveRepositoryIdsForInstallation($installationId), $listedIds);
        foreach ($staleIds as $staleId) {
            echo "  Repository #{$staleId} — no longer accessible to the installation, marking removed ⚠️\n";
            if (!$this->dryRun) {
                $this->repository->markRepositoryRemoved($staleId, $installationId, $this->webhooksHandlerVersion);
            }
            $report["removed"]++;
        }

        return $report;
    }
}
