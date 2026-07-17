<?php

namespace GuiBranco\GStracciniBot\Handlers\Checkup;

use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;

/**
 * Lists every open issue of a repository and makes sure each one has a
 * corresponding `github_issues` row, backfilling any that are missing.
 *
 * GitHub's issues endpoint also returns pull requests (as issues with a
 * `pull_request` property) — those are skipped here since pull requests are
 * checked separately by PullRequestsCheckupHandler.
 */
class IssuesCheckupHandler
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
        $url = "repos/{$repoContext->owner}/{$repoContext->name}/issues?state=open";
        $issues = $this->fetcher->fetchAllPages($repoContext->token, $url);

        $contexts = [];
        $checked = 0;
        $backfilled = 0;

        foreach ($issues as $issue) {
            if (isset($issue->pull_request)) {
                continue;
            }

            $checked++;
            echo "    Issue #{$issue->number}: {$issue->title}\n";

            if (!$this->repository->existsIssue($repoContext->owner, $repoContext->name, (int) $issue->number)) {
                echo "      ⚠️  Missing in github_issues — backfilling\n";
                if (!$this->dryRun) {
                    $this->repository->insertIssue(
                        $issue,
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
                "number" => (int) $issue->number,
                "installationId" => $repoContext->installationId,
                "token" => $repoContext->token,
            ];
        }

        return ["contexts" => $contexts, "checked" => $checked, "backfilled" => $backfilled];
    }
}
