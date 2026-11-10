<?php

namespace GuiBranco\GStracciniBot\Handlers\Checkup;

use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;
use GuiBranco\GStracciniBot\Library\LogsToStream;

/**
 * Lists every repository accessible to an installation and makes sure each
 * one has a corresponding `github_repositories` row, backfilling any that
 * are missing.
 */
class RepositoriesCheckupHandler
{
    use LogsToStream;

    private const LOG_CHANNEL = "checkup";

    private CheckupRepository $repository;
    private GitHubCollectionFetcher $fetcher;
    private bool $dryRun;
    private string $webhooksHandlerVersion;

    public function __construct(
        CheckupRepository $repository,
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
     * @return array{contexts: array<int, object>, checked: int, backfilled: int}
     */
    public function check(object $installationContext): array
    {
        $token = generateInstallationAccessToken((string) $installationContext->id);
        $response = $this->fetcher->fetchAllPages($token, "installation/repositories", "repositories");

        $contexts = [];
        $checked = 0;
        $backfilled = 0;

        foreach ($response as $repository) {
            $checked++;

            if (!$this->repository->existsRepository((int) $repository->id)) {
                $this->logToStream("warning", 'Repository {repo} is missing in github_repositories — backfilling', ["repo" => $repository->full_name, "dryRun" => $this->dryRun]);
                if (!$this->dryRun) {
                    $this->repository->insertRepository($repository, $installationContext->id, $this->webhooksHandlerVersion);
                }
                $backfilled++;
            }

            $contexts[] = (object) [
                "id" => (int) $repository->id,
                "owner" => $repository->owner->login,
                "ownerId" => $repository->owner->id,
                "ownerNodeId" => $repository->owner->node_id,
                "name" => $repository->name,
                "defaultBranch" => $repository->default_branch,
                "installationId" => $installationContext->id,
                "token" => $token,
            ];
        }

        return ["contexts" => $contexts, "checked" => $checked, "backfilled" => $backfilled];
    }
}
