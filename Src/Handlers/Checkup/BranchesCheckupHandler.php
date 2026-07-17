<?php

namespace GuiBranco\GStracciniBot\Handlers\Checkup;

use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;

/**
 * Lists every branch of a repository and makes sure each one has a
 * corresponding (not-since-deleted) `github_branches` row, backfilling any
 * that are missing.
 *
 * The branches list endpoint has no "creator" — the repository owner is
 * used as the best-effort sender placeholder for backfilled rows.
 */
class BranchesCheckupHandler
{
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
    public function check(object $repoContext): array
    {
        $url = "repos/{$repoContext->owner}/{$repoContext->name}/branches";
        $branches = $this->fetcher->fetchAllPages($repoContext->token, $url);

        $contexts = [];
        $checked = 0;
        $backfilled = 0;

        $repositoryOwner = (object) [
            "login" => $repoContext->owner,
            "id" => $repoContext->ownerId,
            "node_id" => $repoContext->ownerNodeId,
        ];

        foreach ($branches as $branch) {
            $checked++;
            echo "    Branch: {$branch->name}\n";

            if (!$this->repository->existsBranch($repoContext->owner, $repoContext->name, $branch->name)) {
                echo "      ⚠️  Missing in github_branches — backfilling\n";
                if (!$this->dryRun) {
                    $this->repository->insertBranch(
                        $repoContext->owner,
                        $repoContext->name,
                        $branch->name,
                        $repoContext->defaultBranch,
                        $repoContext->installationId,
                        $repositoryOwner,
                        $this->webhooksHandlerVersion
                    );
                }
                $backfilled++;
            }

            $contexts[] = (object) [
                "owner" => $repoContext->owner,
                "name" => $repoContext->name,
                "ref" => $branch->name,
                "headSha" => $branch->commit->sha,
                "installationId" => $repoContext->installationId,
                "token" => $repoContext->token,
            ];
        }

        return ["contexts" => $contexts, "checked" => $checked, "backfilled" => $backfilled];
    }
}
