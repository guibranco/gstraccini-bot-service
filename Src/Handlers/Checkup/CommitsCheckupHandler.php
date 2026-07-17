<?php

namespace GuiBranco\GStracciniBot\Handlers\Checkup;

use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;

/**
 * Makes sure commits are registered in `github_pushes`, backfilling any
 * that are missing. Shared by pull requests and branches:
 *
 * - Pull requests: every commit in the PR is checked (there are normally
 *   few of them), mirroring what the existing `@gstraccini review` command
 *   already does (`CommentsHandler::execute_review`).
 * - Branches: `github_pushes` only ever stores the head commit of a push
 *   (not full history), so only the branch's current HEAD commit is
 *   checked/backfilled — walking the entire commit history of every branch
 *   of every repository would be prohibitively expensive.
 */
class CommitsCheckupHandler
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
     * @return array{checked: int, backfilled: int}
     */
    public function checkForPullRequest(object $prContext): array
    {
        $url = "repos/{$prContext->owner}/{$prContext->name}/pulls/{$prContext->number}/commits";
        $commits = $this->fetcher->fetchAllPages($prContext->token, $url);

        $checked = 0;
        $backfilled = 0;

        foreach ($commits as $commit) {
            $checked++;
            if ($this->registerCommit($commit, $prContext->owner, $prContext->name, $prContext->headRef, $prContext->installationId)) {
                $backfilled++;
            }
        }

        return ["checked" => $checked, "backfilled" => $backfilled];
    }

    /**
     * @return array{checked: int, backfilled: int}
     */
    public function checkForBranch(object $branchContext): array
    {
        $url = "repos/{$branchContext->owner}/{$branchContext->name}/commits/{$branchContext->headSha}";
        $response = doRequestGitHub($branchContext->token, $url, null, "GET");
        $this->fetcher->pace($response);

        if ($response->getStatusCode() >= 300) {
            return ["checked" => 0, "backfilled" => 0];
        }

        $commit = json_decode($response->getBody());
        $backfilled = $this->registerCommit($commit, $branchContext->owner, $branchContext->name, $branchContext->ref, $branchContext->installationId) ? 1 : 0;

        return ["checked" => 1, "backfilled" => $backfilled];
    }

    private function registerCommit(object $commit, string $owner, string $repo, string $ref, int $installationId): bool
    {
        if ($this->repository->existsPush($commit->sha)) {
            return false;
        }

        echo "      ⚠️  Commit {$commit->sha} missing in github_pushes — backfilling\n";
        if (!$this->dryRun) {
            $this->repository->insertPush($commit, $owner, $repo, $ref, $installationId, $this->webhooksHandlerVersion);
        }

        return true;
    }
}
