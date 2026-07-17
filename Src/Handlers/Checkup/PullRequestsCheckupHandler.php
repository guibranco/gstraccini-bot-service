<?php

namespace GuiBranco\GStracciniBot\Handlers\Checkup;

use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;

/**
 * Lists every open pull request of a repository and makes sure each one has
 * a corresponding `github_pull_requests` row, backfilling any that are missing.
 */
class PullRequestsCheckupHandler
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
        $url = "repos/{$repoContext->owner}/{$repoContext->name}/pulls?state=open";
        $pullRequests = $this->fetcher->fetchAllPages($repoContext->token, $url);

        $contexts = [];
        $checked = 0;
        $backfilled = 0;

        foreach ($pullRequests as $pullRequest) {
            $checked++;
            echo "    PR #{$pullRequest->number}: {$pullRequest->title}\n";

            if (!$this->repository->existsPullRequest($repoContext->owner, $repoContext->name, (int) $pullRequest->number)) {
                echo "      ⚠️  Missing in github_pull_requests — backfilling\n";
                if (!$this->dryRun) {
                    $this->repository->insertPullRequest(
                        $pullRequest,
                        $repoContext->owner,
                        $repoContext->name,
                        $repoContext->installationId,
                        $this->webhooksHandlerVersion
                    );
                }
                $backfilled++;
            }

            $contexts[] = (object) [
                "owner" => $repoContext->owner,
                "name" => $repoContext->name,
                "number" => (int) $pullRequest->number,
                "headRef" => $pullRequest->head->ref,
                "installationId" => $repoContext->installationId,
                "token" => $repoContext->token,
            ];
        }

        return ["contexts" => $contexts, "checked" => $checked, "backfilled" => $backfilled];
    }
}
