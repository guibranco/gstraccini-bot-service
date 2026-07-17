<?php

namespace GuiBranco\GStracciniBot\Handlers;

use GuiBranco\GStracciniBot\Handlers\Checkup\BranchesCheckupHandler;
use GuiBranco\GStracciniBot\Handlers\Checkup\CommentsCheckupHandler;
use GuiBranco\GStracciniBot\Handlers\Checkup\CommitsCheckupHandler;
use GuiBranco\GStracciniBot\Handlers\Checkup\InstallationsCheckupHandler;
use GuiBranco\GStracciniBot\Handlers\Checkup\IssuesCheckupHandler;
use GuiBranco\GStracciniBot\Handlers\Checkup\PullRequestsCheckupHandler;
use GuiBranco\GStracciniBot\Handlers\Checkup\RepositoriesCheckupHandler;
use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;

/**
 * Manual, deliberately slow reconciliation job: walks GitHub's actual state
 * (installations -> repositories -> open PRs / open issues / branches ->
 * commits) and backfills any row missing from the database, so the
 * existing pollers/handlers pick it up and process it normally.
 *
 * Not wired into any webhook flow — this is only ever run explicitly, via
 * Src/Workers/checkup.php (CLI or the `jobs/checkup` HTTP trigger).
 */
class CheckupHandler
{
    private InstallationsCheckupHandler $installationsHandler;
    private RepositoriesCheckupHandler $repositoriesHandler;
    private PullRequestsCheckupHandler $pullRequestsHandler;
    private IssuesCheckupHandler $issuesHandler;
    private BranchesCheckupHandler $branchesHandler;
    private CommitsCheckupHandler $commitsHandler;
    private CommentsCheckupHandler $commentsHandler;
    private bool $dryRun;

    /** @var array<string, array{checked: int, backfilled: int}> */
    private array $report = [];

    public function __construct(int $throttleSeconds, bool $dryRun, string $version)
    {
        $this->dryRun = $dryRun;

        $webhooksHandlerVersion = "checkup:" . $version;
        $repository = new CheckupRepository();
        $fetcher = new GitHubCollectionFetcher($throttleSeconds);

        $this->installationsHandler = new InstallationsCheckupHandler($repository, $fetcher, $dryRun, $webhooksHandlerVersion);
        $this->repositoriesHandler = new RepositoriesCheckupHandler($repository, $fetcher, $dryRun, $webhooksHandlerVersion);
        $this->pullRequestsHandler = new PullRequestsCheckupHandler($repository, $fetcher, $dryRun, $webhooksHandlerVersion);
        $this->issuesHandler = new IssuesCheckupHandler($repository, $fetcher, $dryRun, $webhooksHandlerVersion);
        $this->branchesHandler = new BranchesCheckupHandler($repository, $fetcher, $dryRun, $webhooksHandlerVersion);
        $this->commitsHandler = new CommitsCheckupHandler($repository, $fetcher, $dryRun, $webhooksHandlerVersion);
        $this->commentsHandler = new CommentsCheckupHandler();

        foreach (["installations", "repositories", "pullRequests", "issues", "branches", "commits", "comments"] as $category) {
            $this->report[$category] = ["checked" => 0, "backfilled" => 0];
        }
    }

    public function run(?int $onlyInstallationId): array
    {
        global $logStream;

        echo "==> Checkup started" . ($this->dryRun ? " (dry run)" : "") . "\n";
        $logStream?->info("Checkup started", ["dryRun" => $this->dryRun, "installationId" => $onlyInstallationId], "checkup");

        $installationsResult = $this->installationsHandler->check($onlyInstallationId);
        $this->accumulate("installations", $installationsResult);

        foreach ($installationsResult["contexts"] as $installationContext) {
            if ($installationContext->suspended) {
                echo "  ⏸️  Installation {$installationContext->login} is suspended — skipping its repositories\n";
                continue;
            }

            $this->checkInstallation($installationContext);
        }

        echo "==> Checkup finished\n";
        $this->printSummary();
        $logStream?->info("Checkup finished", $this->report, "checkup");

        return $this->report;
    }

    private function checkInstallation(object $installationContext): void
    {
        $repositoriesResult = $this->repositoriesHandler->check($installationContext);
        $this->accumulate("repositories", $repositoriesResult);

        foreach ($repositoriesResult["contexts"] as $repoContext) {
            $this->checkRepository($repoContext);
        }
    }

    private function checkRepository(object $repoContext): void
    {
        $pullRequestsResult = $this->pullRequestsHandler->check($repoContext);
        $this->accumulate("pullRequests", $pullRequestsResult);
        foreach ($pullRequestsResult["contexts"] as $prContext) {
            $this->accumulate("commits", $this->commitsHandler->checkForPullRequest($prContext));
            $this->accumulate("comments", $this->commentsHandler->check($prContext));
        }

        $issuesResult = $this->issuesHandler->check($repoContext);
        $this->accumulate("issues", $issuesResult);
        foreach ($issuesResult["contexts"] as $issueContext) {
            $this->accumulate("comments", $this->commentsHandler->check($issueContext));
        }

        $branchesResult = $this->branchesHandler->check($repoContext);
        $this->accumulate("branches", $branchesResult);
        foreach ($branchesResult["contexts"] as $branchContext) {
            $this->accumulate("commits", $this->commitsHandler->checkForBranch($branchContext));
        }
    }

    private function accumulate(string $category, array $result): void
    {
        $this->report[$category]["checked"] += $result["checked"];
        $this->report[$category]["backfilled"] += $result["backfilled"];
    }

    private function printSummary(): void
    {
        echo "\n===== Checkup summary" . ($this->dryRun ? " (dry run — nothing was written)" : "") . " =====\n";
        foreach ($this->report as $category => $counts) {
            echo str_pad($category, 16) . " checked: " . str_pad((string) $counts["checked"], 6) . " backfilled: " . $counts["backfilled"] . "\n";
        }
        echo "comments: rules not yet defined, always skipped (see Handlers/Checkup/CommentsCheckupHandler.php)\n";
    }
}
