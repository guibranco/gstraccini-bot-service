<?php

namespace GuiBranco\GStracciniBot\Handlers;

use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;

/**
 * Handles installation events shared by the HTTP webhook entry point
 * (Src/installations.php) and the queue worker (Src/Workers/installations.php).
 *
 * Maps every repository the installation has access to (via GitHub's
 * `GET /installation/repositories`) into the `github_repositories` table,
 * backfilling any missing rows and marking repositories no longer
 * accessible as removed.
 */
class InstallationsHandler implements IHandler
{
    private CheckupRepository $repository;
    private GitHubCollectionFetcher $fetcher;

    public function __construct(?CheckupRepository $repository = null, ?GitHubCollectionFetcher $fetcher = null)
    {
        $this->repository = $repository ?? new CheckupRepository();
        $this->fetcher = $fetcher ?? new GitHubCollectionFetcher(0);
    }

    /**
     * @param object $installation A row from the `github_installations` table.
     */
    public function handleItem($installation): void
    {
        global $logStream, $version;

        $installationId = (int) $installation->InstallationId;

        echo "Mapping repositories for installation {$installation->AccountLogin} (#{$installationId}):\n\n";
        $logStream?->info(
            "Processing installation event for {$installation->AccountLogin}",
            ['installationId' => $installationId],
            "installations",
            $installation->DeliveryIdText ?? null
        );

        $webhooksHandlerVersion = "users_installations_handler:" . ($version ?? "unknown");

        $token = generateInstallationAccessToken((string) $installationId);
        $repositories = $this->fetcher->fetchAllPages($token, "installation/repositories", "repositories");

        $fetchedRepositoryIds = [];
        foreach ($repositories as $repository) {
            $repositoryId = (int) $repository->id;
            $fetchedRepositoryIds[] = $repositoryId;

            if ($this->repository->existsRepository($repositoryId)) {
                echo "  Repository: {$repository->full_name} — already mapped\n";
                continue;
            }

            $this->repository->insertRepository($repository, $installationId, $webhooksHandlerVersion);
            echo "  Repository: {$repository->full_name} — added ✅\n";
        }

        $activeRepositoryIds = $this->repository->getActiveRepositoryIdsForInstallation($installationId);
        $staleRepositoryIds = array_diff($activeRepositoryIds, $fetchedRepositoryIds);

        foreach ($staleRepositoryIds as $repositoryId) {
            $this->repository->markRepositoryRemoved($repositoryId, $installationId, $webhooksHandlerVersion);
            echo "  Repository #{$repositoryId} — removed ⚠️\n";
        }
    }
}
