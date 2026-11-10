<?php

namespace GuiBranco\GStracciniBot\Handlers;

use DateTimeImmutable;
use GuiBranco\GStracciniBot\Library\DependencyFileLabelService;
use GuiBranco\GStracciniBot\Library\LogContext;
use GuiBranco\GStracciniBot\Library\MarkdownGroupCheckboxValidator;
use GuiBranco\GStracciniBot\Library\PullRequestCodeScanner;
use GuiBranco\GStracciniBot\Library\PullRequestHeadWatcher;
use GuiBranco\GStracciniBot\Library\RecreateRequester;
use GuiBranco\GStracciniBot\Library\VersionBumpAnalyzer;
use GuiBranco\GStracciniBot\Library\VersionBumpCommentBuilder;

define("ISSUES", "/issues/");
define("PULLS", "/pulls/");

/**
 * Handles pull request events shared by the HTTP webhook entry point
 * (Src/pullRequests.php) and the queue worker (Src/Workers/pullRequests.php).
 */
class PullRequestsHandler implements IHandler
{
    private const AWAITING_TRIAGE_LABEL = "🚦 awaiting triage";

    /** The category every entry of this handler is logged under. */
    private const LOG_CATEGORY = "pull_requests";

    /**
     * The delivery the pull request being processed belongs to, sent as the
     * trace id of every entry so all the steps of one run are grouped.
     *
     * @var string|null
     */
    private ?string $traceId = null;

    /**
     * The `owner/name` of the repository of the pull request being processed.
     *
     * @var string
     */
    private string $repoSlug = "";

    /**
     * The number of the pull request being processed.
     *
     * @var integer
     */
    private int $prNumber = 0;

    /**
     * When the processing of the current pull request started, in seconds.
     *
     * @var float
     */
    private float $startedAt = 0.0;


    /**
     * Sends a step of the processing to LogStream, with the repository, the
     * pull request number and the delivery as trace id added to its context.
     *
     * @param string $level   The level: debug, info, notice, warning or error.
     * @param string $message The message template, its placeholders being the keys of the context.
     * @param array  $context The details of the step.
     *
     * @return void
     */
    private function trace(string $level, string $message, array $context): void
    {
        global $logStream;

        if ($logStream === null || method_exists($logStream, $level) === false) {
            return;
        }

        $values = array_merge(["repo" => $this->repoSlug, "pr" => $this->prNumber], $context);
        $logStream->{$level}($message, (new LogContext(...$values))->toArray(), self::LOG_CATEGORY, $this->traceId);

    }//end trace()


    /**
     * Logs the end of the processing of the pull request, with how its check run was left.
     *
     * @param string $outcome How the "pull request" check run was left.
     *
     * @return void
     */
    private function traceFinished(string $outcome): void
    {
        $elapsed = (int) round(((microtime(true) - $this->startedAt) * 1000));
        $this->trace('info', 'Finished processing pull request {repo}#{pr} — check run {outcome} in {elapsed_ms} ms', ["outcome" => $outcome, "elapsed_ms" => $elapsed]);

    }//end traceFinished()


    public function handleItem($pullRequest, $isRetry = false)
    {
        global $logStream;

        $this->traceId = ($pullRequest->DeliveryIdText ?? null);
        $this->repoSlug = "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}";
        $this->prNumber = (int) $pullRequest->Number;
        if ($isRetry === false) {
            $this->startedAt = microtime(true);
        }

        if (!$isRetry) {
            echo "https://github.com/{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}/pull/{$pullRequest->Number}:\n\n";
            $logStream?->info(
                'Processing pull request {repo}#{pr}',
                (new LogContext(repo: "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}", pr: $pullRequest->Number, sender: $pullRequest->Sender))->toArray(),
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
        }

        $config = loadConfig();
        $this->trace('debug', 'PR {repo}#{pr}: generating installation token', ["installation_id" => $pullRequest->InstallationId, "retry" => $isRetry]);
        $token = generateInstallationToken($pullRequest->InstallationId, $pullRequest->RepositoryName);
        $metadata = $this->createMetadata($token, $pullRequest, $config);
        $this->trace('debug', 'PR {repo}#{pr}: fetching pull request from GitHub', []);
        $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
        try {
            $pullRequestResponse->ensureSuccessStatus();
        } catch (\Exception $e) {
            echo "Failed to fetch PR #{$pullRequest->Number}: {$e->getMessage()} ❌\n";
            $logStream?->error(
                'Failed to fetch pull request {repo}#{pr}: {error}',
                (new LogContext(repo: "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}", pr: $pullRequest->Number))->withError($e)->toArray(),
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
            return;
        }
        $pullRequestUpdated = json_decode($pullRequestResponse->getBody());
        $fetched = [];
        $fetched["state"] = $pullRequestUpdated->state;
        $fetched["mergeable_state"] = ($pullRequestUpdated->mergeable_state ?? null);
        $fetched["mergeable"] = ($pullRequestUpdated->mergeable ?? null);
        $fetched["head_sha"] = ($pullRequestUpdated->head->sha ?? null);
        $fetched["head_ref"] = ($pullRequestUpdated->head->ref ?? null);
        $fetched["base_ref"] = ($pullRequestUpdated->base->ref ?? null);
        $fetched["draft"] = ($pullRequestUpdated->draft ?? null);
        $fetched["author"] = ($pullRequestUpdated->user->login ?? null);
        $this->trace('debug', 'PR {repo}#{pr} fetched: state {state}, mergeable_state {mergeable_state}, head {head_sha}', $fetched);

        if ($pullRequestUpdated->state === "closed") {
            $logStream?->info(
                'PR {repo}#{pr} is closed — cleaning up labels',
                (new LogContext(repo: "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}", pr: $pullRequest->Number))->toArray(),
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
            $this->removeIssueWipLabel($metadata, $pullRequest);
            $this->removeLabels($metadata, $pullRequestUpdated);
            $this->checkForOtherPullRequests($metadata, $pullRequest);
            removeUnreadActionsForPullRequest($pullRequestUpdated->node_id);
            $this->trace('debug', 'PR {repo}#{pr}: removed unread actions of the closed pull request', []);

            if ($pullRequestUpdated->merged) {
                $this->trace('info', 'PR {repo}#{pr} was merged — recording recent activity', []);
                recordRecentActivity(
                    $pullRequest->RepositoryOwner,
                    $pullRequest->RepositoryName,
                    $pullRequest->InstallationId,
                    "merged_pr",
                    $pullRequestUpdated->title,
                    $pullRequestUpdated->html_url,
                    $pullRequestUpdated->id,
                    $pullRequestUpdated->number,
                    $pullRequestUpdated->node_id
                );
            }
        }

        if ($pullRequestUpdated->state != "open") {
            echo "PR State: {$pullRequestUpdated->state} ⛔\n";
            $logStream?->info(
                'PR {repo}#{pr} state is {state} — skipping',
                (new LogContext(repo: "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}", pr: $pullRequest->Number, state: $pullRequestUpdated->state))->toArray(),
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
            updatePullRequestClosedState($pullRequest->Sequence, $pullRequestUpdated->merged ?? false);

            return;
        }

        if ($isRetry === false && $pullRequestUpdated->mergeable_state === "unknown") {
            sleep(5);
            echo "State: {$pullRequestUpdated->mergeable_state} - Retrying #{$pullRequestUpdated->number} - Sender: " . $pullRequest->Sender . " 🔄\n";
            $logStream?->warning(
                'PR {repo}#{pr} has unknown mergeable state — retrying',
                (new LogContext(repo: "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}", pr: $pullRequest->Number, sender: $pullRequest->Sender))->toArray(),
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
            $this->handleItem($pullRequest, true);
            return;
        }

        $existingCheckRun = $this->findExistingCheckRun($metadata, $pullRequestUpdated->head->sha, "pull request");
        $alreadyActionRequired = $existingCheckRun !== null && $existingCheckRun->conclusion === "action_required";
        $checkRunId = null;

        $existing = [];
        $existing["check_run_id"] = ($existingCheckRun->id ?? null);
        $existing["status"] = ($existingCheckRun->status ?? null);
        $existing["conclusion"] = ($existingCheckRun->conclusion ?? null);
        $existing["already_action_required"] = $alreadyActionRequired;
        $this->trace('debug', 'PR {repo}#{pr}: existing "pull request" check run {check_run_id} ({conclusion})', $existing);

        if (!$alreadyActionRequired) {
            $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, "pull request");
            $this->trace('debug', 'PR {repo}#{pr}: "pull request" check run {check_run_id} set in progress', ["check_run_id" => $checkRunId]);
        }

        try {
            $this->runChecks($pullRequest, $pullRequestUpdated, $metadata, $config, $existingCheckRun, $alreadyActionRequired, $checkRunId);
        } catch (\Throwable $e) {
            $this->trace('error', 'PR {repo}#{pr}: processing failed: {error}', ["error" => $e->getMessage(), "file" => $e->getFile(), "line" => $e->getLine(), "check_run_id" => $checkRunId]);
            // Only a check run this run started is failed; an existing action_required one keeps its state.
            if ($checkRunId !== null) {
                failCheckRunOnError($metadata, $checkRunId, "pull request", $e);
            }

            throw $e;
        }
    }


    /**
     * Runs the pull request checks once its check run is in progress, and completes the check run.
     * Split from handleItem() so any failure in here completes the check run as failed instead of
     * leaving it in progress.
     *
     * @param object       $pullRequest        The pull request item being processed.
     * @param object       $pullRequestUpdated The pull request as returned by the GitHub API.
     * @param array        $metadata           Metadata for the GitHub API requests.
     * @param object       $config             The bot configuration.
     * @param object|null  $existingCheckRun   The bot's existing check run for the head commit, if any.
     * @param boolean      $actionRequired     Whether the existing check run already requires action.
     * @param integer|null $checkRunId         The check run started for this run, if any.
     *
     * @return void
     */
    private function runChecks($pullRequest, $pullRequestUpdated, array $metadata, $config, ?object $existingCheckRun, bool $actionRequired, ?int $checkRunId): void
    {
        $this->trace('debug', 'PR {repo}#{pr}: step enable auto-merge', []);
        $this->enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config);
        $this->trace('debug', 'PR {repo}#{pr}: step add labels from linked issues', []);
        $this->addLabelsFromIssue($metadata, $pullRequest, $pullRequestUpdated);
        $this->trace('debug', 'PR {repo}#{pr}: step update branch', []);
        $this->updateBranch($metadata, $pullRequest, $pullRequestUpdated);

        $labelsToAdd = [];
        if (strtolower($pullRequestUpdated->user->type) === "bot") {
            $labelsToAdd[] = "🤖 bot";
        }

        $collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
        $collaboratorsLogins = array_column(json_decode($collaboratorsResponse->getBody()), "login");

        $reviewsLogins = $this->getReviewsLogins($metadata);
        $iAmTheOwner = in_array($config->ownerHandler, $collaboratorsLogins);
        $this->trace('debug', 'PR {repo}#{pr}: step review and assign', ["collaborators_status" => $collaboratorsResponse->getStatusCode(), "owner_is_collaborator" => $iAmTheOwner]);
        $labelsToAdd = array_merge(
            $labelsToAdd,
            $this->reviewAndAssign($metadata, $pullRequest, $pullRequestUpdated, $config, $collaboratorsLogins, $reviewsLogins, $iAmTheOwner)
        );

        $this->trace('debug', 'PR {repo}#{pr}: step awaiting-triage cleanup', []);
        $this->removeAwaitingTriageIfReviewed($metadata, $pullRequestUpdated, $collaboratorsLogins, $reviewsLogins, $pullRequest->Sender);

        if (empty($labelsToAdd) === false) {
            $body = ["labels" => $labelsToAdd];
            $response = doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");
            $this->trace('info', 'PR {repo}#{pr}: added labels {labels} (HTTP {status})', ["labels" => implode(", ", $labelsToAdd), "status" => $response->getStatusCode()]);
        }

        if ($iAmTheOwner) {
            $this->trace('debug', 'PR {repo}#{pr}: step resolve conflicts', []);
            $this->resolveConflicts($metadata, $pullRequest, $pullRequestUpdated);
            $this->trace('debug', 'PR {repo}#{pr}: step comment to merge', []);
            $this->handleCommentToMerge($metadata, $pullRequest, $collaboratorsLogins);
        }

        if ($iAmTheOwner === false) {
            $this->trace('debug', 'PR {repo}#{pr}: owner {owner} is not a collaborator — skipping conflicts and merge comment', ["owner" => $config->ownerHandler]);
        }

        $this->trace('debug', 'PR {repo}#{pr}: step version bump', []);
        $versionBumpResolved = $this->checkVersionBump($metadata, $pullRequestUpdated);

        $this->trace('debug', 'PR {repo}#{pr}: step description check', []);
        $this->checkPullRequestDescription($metadata, $pullRequestUpdated, $pullRequest->Sender);
        $this->trace('debug', 'PR {repo}#{pr}: step content check', []);
        $this->checkPullRequestContent($metadata, $pullRequestUpdated);
        $this->trace('debug', 'PR {repo}#{pr}: step dependency changes check', []);
        $this->checkDependencyChanges($metadata, $pullRequestUpdated);

        if ($versionBumpResolved === true) {
            setCheckRunSucceeded($metadata, ($checkRunId ?? $existingCheckRun->id), "pull request");
            $this->traceFinished("success");
            return;
        }

        if ($actionRequired === true) {
            echo "State: Version bump decision pending - check run already action_required, skipping redundant update ⚠️\n";
            $this->traceFinished("action_required (unchanged)");
            return;
        }

        setCheckRunActionRequired(
            $metadata,
            $checkRunId,
            "pull request",
            "Waiting on a version bump decision. Check one of the boxes on the bot's comment to continue."
        );
        echo "State: Version bump decision pending - marking 'pull request' check run as action_required ⚠️\n";
        $this->traceFinished("action_required");

    }//end runChecks()


    /**
     * Assigns the collaborators, approves as the bot, auto-approves for trusted submitters and requests reviewers.
     *
     * @param array   $metadata            Metadata for the GitHub API requests.
     * @param object  $pullRequest         The pull request item being processed.
     * @param object  $pullRequestUpdated  The pull request as returned by the GitHub API.
     * @param object  $config              The bot configuration.
     * @param array   $collaboratorsLogins The logins of the repository collaborators.
     * @param array   $reviewsLogins       The logins of the users who reviewed the pull request.
     * @param boolean $iAmTheOwner         Whether the bot owner is a collaborator of the repository.
     *
     * @return string[] The labels to add to the pull request.
     */
    private function reviewAndAssign(array $metadata, $pullRequest, $pullRequestUpdated, $config, array $collaboratorsLogins, array $reviewsLogins, bool $iAmTheOwner): array
    {
        $botLogin = "{$config->botName}[bot]";
        $botReviewed = in_array($botLogin, $reviewsLogins, true);
        $invokerReviewed = (count(array_intersect($reviewsLogins, $collaboratorsLogins)) > 0);
        $autoReview = in_array($pullRequest->Sender, $config->pullRequests->autoReviewSubmitters, true);
        $reviewState = [];
        $reviewState["collaborators"] = implode(", ", $collaboratorsLogins);
        $reviewState["reviewers"] = implode(", ", array_unique($reviewsLogins));
        $reviewState["bot_reviewed"] = $botReviewed;
        $reviewState["collaborator_reviewed"] = $invokerReviewed;
        $reviewState["auto_review_submitter"] = $autoReview;
        $reviewState["sender"] = $pullRequest->Sender;
        $this->trace('debug', 'PR {repo}#{pr}: review state — bot reviewed {bot_reviewed}, collaborator reviewed {collaborator_reviewed}', $reviewState);

        if ($pullRequestUpdated->assignee === null) {
            $response = doRequestGitHub($metadata["token"], $metadata["assigneesUrl"], ["assignees" => $collaboratorsLogins], "POST");
            $this->trace('info', 'PR {repo}#{pr}: assigned collaborators (HTTP {status})', ["assignees" => implode(", ", $collaboratorsLogins), "status" => $response->getStatusCode()]);
        }

        if ($botReviewed === false) {
            $response = doRequestGitHub($metadata["token"], $metadata["reviewsUrl"], ["event" => "APPROVE"], "POST");
            $this->trace('info', 'PR {repo}#{pr}: approved by the bot (HTTP {status})', ["status" => $response->getStatusCode()]);
        }

        if ($invokerReviewed === true) {
            $this->trace('debug', 'PR {repo}#{pr}: a collaborator already reviewed — no review request', []);
            return [];
        }

        if ($autoReview === true && $iAmTheOwner === true) {
            $bodyMsg = "Automatically approved by {$metadata["botNameMarkdown"]}";
            $response = doRequestGitHub($metadata["userToken"], $metadata["reviewsUrl"], ["event" => "APPROVE", "body" => $bodyMsg], "POST");
            $this->trace('info', 'PR {repo}#{pr}: auto-approved as owner for submitter {sender} (HTTP {status})', ["sender" => $pullRequest->Sender, "status" => $response->getStatusCode()]);
        }

        if ($autoReview === true) {
            return [];
        }

        return $this->requestReviewers($metadata, $pullRequest->Sender, $collaboratorsLogins);

    }//end reviewAndAssign()


    /**
     * Requests the collaborators other than the sender as reviewers, and flags a sender who
     * is not a collaborator for triage.
     *
     * @param array  $metadata            Metadata for the GitHub API requests.
     * @param string $sender              The login of the pull request sender.
     * @param array  $collaboratorsLogins The logins of the repository collaborators.
     *
     * @return string[] The labels to add to the pull request.
     */
    private function requestReviewers(array $metadata, string $sender, array $collaboratorsLogins): array
    {
        $reviewers = array_values(array_diff($collaboratorsLogins, [$sender]));
        if (count($reviewers) > 0) {
            $response = doRequestGitHub($metadata["token"], $metadata["requestReviewUrl"], ["reviewers" => $reviewers], "POST");
            $this->trace('info', 'PR {repo}#{pr}: requested review from {reviewers} (HTTP {status})', ["reviewers" => implode(", ", $reviewers), "status" => $response->getStatusCode()]);
        }

        if (in_array($sender, $collaboratorsLogins, true) === true) {
            return [];
        }

        $this->trace('debug', 'PR {repo}#{pr}: sender {sender} is not a collaborator — flagging for triage', ["sender" => $sender]);
        return [self::AWAITING_TRIAGE_LABEL];

    }//end requestReviewers()


    /**
     * Finds the bot's existing check run of the given type for a commit sha, if any.
     * Used to avoid re-creating and re-completing a check run on every PR event while
     * it's already sitting in a terminal state (e.g. `action_required`) with nothing changed.
     *
     * @param array $metadata Metadata for the GitHub API request
     * @param string $sha The commit sha to look up check runs for
     * @param string $type The check type (e.g. "pull request"), used to match the check run name
     * @return object|null The matching check run, or null if none exists yet
     */
    private function findExistingCheckRun(array $metadata, string $sha, string $type): ?object
    {
        $url = "repos/{$metadata['owner']}/{$metadata['repo']}/commits/{$sha}/check-runs";
        $response = doRequestGitHub($metadata["token"], $url, null, "GET");
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $checkRunName = constant("BOT_CHECK_MESSAGE_PREFIX") . ucwords($type);
        foreach (json_decode($response->getBody())->check_runs as $checkRun) {
            if ($checkRun->name === $checkRunName) {
                return $checkRun;
            }
        }

        return null;
    }

    /**
     * Analyzes a pull request for a version bump decision: if it looks like a feature
     * (via title, branch name, or labels) and no GitVersion `+semver` directive is
     * already present in its title or commits, an actionable comment is posted asking
     * the user to choose a minor bump, a major bump, or no bump at all.
     *
     * @param array $metadata Metadata for the GitHub API request
     * @param object $pullRequestUpdated The updated pull request data
     * @return bool True if the decision is resolved (no action needed, or already handled),
     *              false if it's still pending user input.
     */
    private function checkVersionBump(array $metadata, object $pullRequestUpdated): bool
    {
        $analyzer = new VersionBumpAnalyzer();

        $messages = [$pullRequestUpdated->title];
        $commitsResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"] . "/commits", null, "GET");
        if ($commitsResponse->getStatusCode() < 300) {
            foreach (json_decode($commitsResponse->getBody()) as $commit) {
                $messages[] = $commit->commit->message;
            }
        }

        if ($analyzer->hasSemverDirective($messages)) {
            $this->trace('debug', 'PR {repo}#{pr}: version bump resolved — +semver directive found in {messages} title/commit messages', ["messages" => count($messages)]);
            return true;
        }

        $labels = array_column($pullRequestUpdated->labels ?? [], "name");
        if (!$analyzer->looksLikeFeature($pullRequestUpdated->title, $pullRequestUpdated->head->ref, $labels)) {
            $this->trace('debug', 'PR {repo}#{pr}: version bump resolved — does not look like a feature', ["title" => $pullRequestUpdated->title, "head_ref" => $pullRequestUpdated->head->ref]);
            return true;
        }

        // A "no version bump" decision never adds a +semver directive, so it wouldn't
        // otherwise be recognized as resolved on later events (e.g. the check run
        // getting flipped back to action_required). The completion marker is the
        // durable signal that the decision was already made.
        if ($this->findCommentByContent($metadata, $pullRequestUpdated, VersionBumpCommentBuilder::COMPLETION_MARKER)) {
            $this->trace('debug', 'PR {repo}#{pr}: version bump resolved — decision already recorded in a comment', []);
            return true;
        }

        if ($this->findCommentByContent($metadata, $pullRequestUpdated, VersionBumpCommentBuilder::MARKER) === true) {
            $this->trace('debug', 'PR {repo}#{pr}: version bump decision comment already posted — waiting on the user', []);
            return false;
        }

        $builder = new VersionBumpCommentBuilder();
        $body = $builder->build($pullRequestUpdated->title);
        $response = doRequestGitHub($metadata["token"], $metadata["commentsUrl"], ["body" => $body], "POST");
        if ($response->getStatusCode() >= 300) {
            $this->trace('warning', 'PR {repo}#{pr}: failed to post version bump decision comment (HTTP {status})', ["status" => $response->getStatusCode()]);
            return false;
        }

        $this->trace('info', 'PR {repo}#{pr}: posted version bump decision comment (HTTP {status})', ["status" => $response->getStatusCode()]);

        return false;
    }

    /**
     * Removes the 'awaiting triage' label if the PR has been reviewed by all required reviewers
     * and the last review occurred after the last commit.
     *
     * @param array $metadata Metadata for the GitHub API request
     * @param object $pullRequestUpdated The updated pull request data
     * @param array $collaboratorsLogins Array of collaborator login names
     * @param array $reviewsLogins Array of reviewer login names who have reviewed
     * @param string $sender The PR sender's login
     * @return void
     */
    private function removeAwaitingTriageIfReviewed($metadata, $pullRequestUpdated, $collaboratorsLogins, $reviewsLogins, $sender)
    {
        $currentLabels = array_column($pullRequestUpdated->labels, "name");
        if (in_array(self::AWAITING_TRIAGE_LABEL, $currentLabels) === false) {
            $this->trace('debug', 'PR {repo}#{pr}: awaiting triage — label not present, nothing to do', []);
            return;
        }

        if (in_array($sender, $collaboratorsLogins)) {
            $this->trace('debug', 'PR {repo}#{pr}: awaiting triage — sender {sender} is a collaborator, keeping label', ["sender" => $sender]);
            return;
        }

        $requiredReviewers = $collaboratorsLogins;
        if (in_array($sender, $requiredReviewers)) {
            $requiredReviewers = array_values(array_diff($requiredReviewers, array($sender)));
        }

        if (empty($requiredReviewers)) {
            return;
        }

        $reviewedCollaborators = array_intersect($reviewsLogins, $collaboratorsLogins);
        if (count($reviewedCollaborators) < count($requiredReviewers)) {
            echo "Awaiting triage: Not all required reviewers have reviewed ({" . count($reviewedCollaborators) . "}/{" . count($requiredReviewers) . "}) ⏳\n";
            $this->trace('debug', 'PR {repo}#{pr}: awaiting triage — {reviewed}/{required} required reviewers reviewed', ["reviewed" => count($reviewedCollaborators), "required" => count($requiredReviewers)]);
            return;
        }

        $lastReviewTimestamp = $this->getLastReviewTimestamp($metadata);
        if ($lastReviewTimestamp === null) {
            return;
        }

        $lastCommitTimestamp = $this->getLastCommitTimestamp($metadata, $pullRequestUpdated);
        if ($lastCommitTimestamp === null) {
            return;
        }

        if (strtotime($lastReviewTimestamp) <= strtotime($lastCommitTimestamp)) {
            echo "Awaiting triage: Last review ({$lastReviewTimestamp}) is not after last commit ({$lastCommitTimestamp}) ⏰\n";
            $this->trace('debug', 'PR {repo}#{pr}: awaiting triage — last review {last_review} is not after last commit {last_commit}', ["last_review" => $lastReviewTimestamp, "last_commit" => $lastCommitTimestamp]);
            return;
        }

        $labelToRemove = str_replace(" ", "%20", self::AWAITING_TRIAGE_LABEL);
        $url = $metadata["pullRequestUrl"] . "/labels/{$labelToRemove}";
        $response = doRequestGitHub($metadata["token"], $url, null, "DELETE");

        if ($response->getStatusCode() < 300) {
            echo "Awaiting triage: Label removed - All reviewers have reviewed and last review is recent ✅\n";
            $this->trace('info', 'PR {repo}#{pr}: awaiting triage label removed — all reviewers reviewed after the last commit', []);
        } else {
            echo "Awaiting triage: Failed to remove label (HTTP {$response->getStatusCode()}) ❌\n";
            $this->trace('warning', 'PR {repo}#{pr}: failed to remove awaiting triage label (HTTP {status})', ["status" => $response->getStatusCode()]);
        }
    }

    /**
     * Gets the timestamp of the last review for the pull request
     *
     * @param array $metadata Metadata for the GitHub API request
     * @return string|null The timestamp of the last review, or null if no reviews found
     */
    private function getLastReviewTimestamp($metadata)
    {
        $reviewsResponse = doRequestGitHub($metadata["token"], $metadata["reviewsUrl"], null, "GET");
        if ($reviewsResponse->getStatusCode() >= 300) {
            return null;
        }

        $reviews = json_decode($reviewsResponse->getBody());
        if (empty($reviews)) {
            return null;
        }

        usort($reviews, function ($a, $b) {
            return strtotime($b->submitted_at) - strtotime($a->submitted_at);
        });

        return $reviews[0]->submitted_at;
    }

    /**
     * Gets the timestamp of the last commit in the pull request
     *
     * @param array $metadata Metadata for the GitHub API request
     * @param object $pullRequestUpdated The updated pull request data
     * @return string|null The timestamp of the last commit, or null if not found
     */
    private function getLastCommitTimestamp($metadata, $pullRequestUpdated)
    {
        $commitsUrl = $metadata["pullRequestUrl"] . "/commits";
        $commitsResponse = doRequestGitHub($metadata["token"], $commitsUrl, null, "GET");

        if ($commitsResponse->getStatusCode() >= 300) {
            return isset($pullRequestUpdated->head->repo->pushed_at) ? $pullRequestUpdated->head->repo->pushed_at : null;
        }

        $commits = json_decode($commitsResponse->getBody());
        if (empty($commits)) {
            return null;
        }

        $lastCommit = end($commits);
        return $lastCommit->commit->committer->date;
    }

    /**
     * Creates metadata required for performing GitHub API requests.
     *
     * @param string $token The GitHub API token.
     * @param object $pullRequest The pull request object containing details such as RepositoryOwner, RepositoryName, and Number.
     * @param object $config The configuration object containing settings like botName and dashboardUrl.
     *
     * @return array An associative array with API endpoints and tokens for further requests.
     */
    private function createMetadata($token, $pullRequest, $config)
    {
        global $gitHubUserToken;

        $prQueryString =
            "pull-requests/?owner=" . $pullRequest->RepositoryOwner .
            "&repo=" . $pullRequest->RepositoryName .
            "&pullRequest=" . $pullRequest->Number;
        $repoPrefix = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName;
        return array(
            "token" => $token,
            "userToken" => $gitHubUserToken,
            "mergeComment" => "@depfu merge",
            "commentsUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/comments",
            "pullRequestUrl" => $repoPrefix . PULLS . $pullRequest->Number,
            "pullRequestsUrl" => $repoPrefix . "/pulls",
            "reviewsUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/reviews",
            "assigneesUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/assignees",
            "collaboratorsUrl" => $repoPrefix . "/collaborators",
            "requestReviewUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/requested_reviewers",
            "checkRunUrl" => $repoPrefix . "/check-runs",
            "issuesUrl" => $repoPrefix . "/issues",
            "labelsUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/labels",
            "compareUrl" => $repoPrefix . "/compare/",
            "botNameMarkdown" => "[" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")",
            "dashboardUrl" => $config->dashboardUrl . $prQueryString,
            "owner" => $pullRequest->RepositoryOwner,
            "repo" => $pullRequest->RepositoryName,
        );
    }

    /**
     * Retrieves the pull request template content from the repository
     * Looks for PR template files in the current repository and the
     * .github community health repository
     * Follows GitHub's template resolution rules with case-insensitive matching.
     *
     * @param array $metadata An associative array containing metadata about the pull request.
     *                       Must include 'owner', 'repo', and 'token' keys for API access.
     *
     * @return string|null The content of the pull request template if found, null otherwise.
     */
    private function getPullRequestTemplate($metadata)
    {
        $fileNames = [
            'pull_request_template.md',
            'PULL_REQUEST_TEMPLATE.md',
            'Pull_Request_Template.md'
        ];

        $directories = [
            '',                                   // Root level
            'docs/',                              // docs/ directory
            '.github/',                           // .github directory
            '.github/PULL_REQUEST_TEMPLATE/'      // PR template directory
        ];

        $allPaths = [];
        foreach ($directories as $dir) {
            foreach ($fileNames as $fileName) {
                $allPaths[] = $dir . $fileName;
            }
        }

        $template = $this->searchTemplateInRepository($metadata, $metadata['repo'], $allPaths);
        if ($template !== null) {
            return $template;
        }

        $template = $this->searchTemplateInRepository($metadata, '.github', $allPaths);
        if ($template !== null) {
            return $template;
        }

        return null;
    }

    /**
     * Helper function to search for template in a specific repository
     *
     * @param array $metadata Metadata for the GitHub API request, containing 'owner', 'token', etc.
     * @param string $repoName The name of the repository to search in
     * @param array $paths List of possible template file paths to check in the repository. Each entry should be a relative path from the repository root.
     * @return string|null The decoded template content if found, null otherwise.
     */
    private function searchTemplateInRepository($metadata, $repoName, $paths)
    {
        foreach ($paths as $path) {
            try {
                $url = "/repos/{$metadata['owner']}/{$repoName}/contents/{$path}";
                $response = doRequestGitHub($metadata["token"], $url, null, "GET");
                if ($response !== false) {
                    $fileData = json_decode($response->getBody(), true);
                    if (isset($fileData['content']) &&
                        $fileData['encoding'] === 'base64') {
                        return base64_decode($fileData['content']);
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Updates the pull request description and optionally adds a comment
     * Handles both template application and default message scenarios
     *
     * @param array  $metadata Metadata for the GitHub API request
     * @param string $content The content to set as the pull request description
     */
    private function updatePullRequestDescription($metadata, $content)
    {
        $response = doRequestGitHub(
            $metadata["token"],
            $metadata["pullRequestUrl"],
            array("body" => $content),
            "PATCH"
        );
        $this->trace('info', 'PR {repo}#{pr}: description updated ({length} chars, HTTP {status})', ["length" => strlen($content), "status" => $response->getStatusCode()]);

        if (strpos($content, 'Please provide a description') !== false) {
            $comment = array("body" => $content);
            $response = doRequestGitHub($metadata["token"], $metadata["commentsUrl"], $comment, "POST");
            $this->trace('info', 'PR {repo}#{pr}: posted comment asking for a description (HTTP {status})', ["status" => $response->getStatusCode()]);
        }
    }

    /**
     * Checks the pull request description for compliance with the required standards.
     * @return void
     * If the description is missing or too short, it applies
     * a template or default message.
     * Validates the presence of groups and checkboxes in the description.
     *
     * @param array $metadata Metadata for the GitHub API request
     * @param object  $pullRequestUpdated The updated pull request data
     */
    private function checkPullRequestDescription($metadata, $pullRequestUpdated, $sender)
    {
        $type = "pull request description";
        $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, $type);

        $dependencyBots = ["dependabot[bot]", "depfu[bot]", "codefactor-io[bot]", "deepsource-autofix[bot]", "net-framework-updater[bot]"];
        if (in_array($sender, $dependencyBots)) {
            setCheckRunSucceeded($metadata, $checkRunId, $type, "Skipped for dependency bot PR");
            $this->trace('debug', 'PR {repo}#{pr}: description check skipped for dependency bot {sender}', ["sender" => $sender, "check_run_id" => $checkRunId]);
            return;
        }

        $bodyLength = isset($pullRequestUpdated->body) ? strlen($pullRequestUpdated->body) : 0;
        if ($bodyLength === 0) {
            $templateContent = $this->getPullRequestTemplate($metadata);
            if ($templateContent) {
                $this->trace('info', 'PR {repo}#{pr}: empty description — applying the pull request template', []);
                $this->updatePullRequestDescription($metadata, $templateContent);
                return;
            }

            $defaultMessage = "Please provide a description for this pull request.";
            $this->trace('info', 'PR {repo}#{pr}: empty description and no template found — asking for a description', []);
            $this->updatePullRequestDescription($metadata, $defaultMessage);
            setCheckRunFailed($metadata, $checkRunId, $type, $defaultMessage);
            return;
        }

        if ($bodyLength <= 250) {
            setCheckRunFailed($metadata, $checkRunId, $type, "Pull request description too short: {$bodyLength} characters (at least 250 characters long required).");
            $this->trace('info', 'PR {repo}#{pr}: description check failed — too short ({length} chars)', ["length" => $bodyLength, "check_run_id" => $checkRunId]);
            return;
        }

        $validator = new MarkdownGroupCheckboxValidator();
        $validationResult = $validator->validateCheckboxes($pullRequestUpdated->body);
        if (isset($validationResult['errors']) && !empty($validationResult['errors'])) {
            $message = $validator->generateReport($validationResult);
            setCheckRunFailed($metadata, $checkRunId, $type, $message);
            $this->trace('info', 'PR {repo}#{pr}: description check failed — {errors} checkbox group error(s)', ["errors" => count($validationResult['errors']), "check_run_id" => $checkRunId]);
            return;
        } elseif ($validationResult["found"] === false || $validationResult["found"] === 0) {
            setCheckRunSucceeded($metadata, $checkRunId, $type, "No groups or checkboxes found in the PR body.");
            $this->trace('debug', 'PR {repo}#{pr}: description check passed — no checkbox groups found', ["length" => $bodyLength, "check_run_id" => $checkRunId]);
            return;
        }

        setCheckRunSucceeded($metadata, $checkRunId, $type);
        $this->trace('debug', 'PR {repo}#{pr}: description check passed', ["length" => $bodyLength, "check_run_id" => $checkRunId]);
    }

    private function checkPullRequestContent($metadata, $pullRequestUpdated)
    {
        $type = "pull request content";
        $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, $type);
        $diffResponse = getPullRequestDiff($metadata);
        $diff = $diffResponse->getBody();
        $scanner = new PullRequestCodeScanner();
        $comments = $scanner->scanDiffForKeywords($diff);
        $report = $scanner->generateReport($comments);
        if (!empty($comments)) {
            setCheckRunFailed($metadata, $checkRunId, $type, $report);
            $this->trace('info', 'PR {repo}#{pr}: content check failed — {findings} finding(s) in the diff', ["findings" => count($comments), "diff_bytes" => strlen((string) $diff), "check_run_id" => $checkRunId]);
            return;
        }

        setCheckRunSucceeded($metadata, $checkRunId, $type, $report);
        $this->trace('debug', 'PR {repo}#{pr}: content check passed', ["diff_bytes" => strlen((string) $diff), "check_run_id" => $checkRunId]);
    }

    /**
     * Checks for dependency file changes in a pull request and applies appropriate labels.
     *
     * @param array $metadata Metadata for the GitHub API request
     * @param object $pullRequestUpdated The updated pull request data
     * @return void
     */
    private function checkDependencyChanges($metadata, $pullRequestUpdated): void
    {
        $type = "dependency changes";
        $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, $type);

        $diffResponse = getPullRequestDiff($metadata);
        $diff = $diffResponse->getBody();

        $dependencyService = new DependencyFileLabelService();
        $detectedDependencies = $dependencyService->detectDependencyChanges($diff);

        if (empty($detectedDependencies)) {
            setCheckRunSucceeded($metadata, $checkRunId, $type, "No dependency file changes detected.");
            $this->trace('debug', 'PR {repo}#{pr}: dependency check — no dependency file changes', ["check_run_id" => $checkRunId]);
            return;
        }

        $labelsToAdd = ["📦 dependencies"];

        foreach (array_keys($detectedDependencies) as $label) {
            $labelsToAdd[] = $label;
        }

        $body = array("labels" => $labelsToAdd);
        $response = doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");

        $report = "Detected dependency changes for package managers: " . implode(", ", array_values($detectedDependencies));
        setCheckRunSucceeded($metadata, $checkRunId, $type, $report);
        $this->trace('info', 'PR {repo}#{pr}: dependency changes detected ({managers}) — labels added (HTTP {status})', ["managers" => implode(", ", array_values($detectedDependencies)), "labels" => implode(", ", $labelsToAdd), "status" => $response->getStatusCode(), "check_run_id" => $checkRunId]);
    }

    private function removeLabels($metadata, $pullRequestUpdated)
    {
        $labelsLookup = [
            "🚦 awaiting triage",
            "⏳ awaiting response",
            "🛠 WIP"
        ];

        $labels = array_column($pullRequestUpdated->labels, "name");
        $intersect = array_intersect($labelsLookup, $labels);

        foreach ($intersect as $label) {
            $encoded = str_replace(" ", "%20", $label);
            $url = "{$metadata["pullRequestUrl"]}/labels/{$encoded}";
            $response = doRequestGitHub($metadata["token"], $url, null, "DELETE");
            $this->trace('info', 'PR {repo}#{pr}: removed label {label} from closed pull request (HTTP {status})', ["label" => $label, "status" => $response->getStatusCode()]);
        }
    }

    private function checkForOtherPullRequests($metadata, $pullRequest)
    {
        $pullRequestsOpenResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestsUrl"] . "?state=open&sort=created", null, "GET");
        $pullRequestsOpen = json_decode($pullRequestsOpenResponse->getBody());
        $any = false;

        if (count($pullRequestsOpen) === 0) {
            echo "No other pull requests to review ❌\n";
            $this->trace('debug', 'PR {repo}#{pr}: no other open pull requests to trigger', []);
            return;
        }

        $this->trace('debug', 'PR {repo}#{pr}: {open} open pull request(s) — looking for one to trigger', ["open" => count($pullRequestsOpen)]);

        foreach ($pullRequestsOpen as $pullRequestPending) {
            if ($pullRequest->Number === $pullRequestPending->number) {
                continue;
            }
            if ($pullRequestPending->auto_merge !== null) {
                $this->triggerReview($pullRequest, $pullRequestPending);
                $any = true;
                break;
            }
        }

        if ($any) {
            return;
        }

        foreach ($pullRequestsOpen as $pullRequestPending) {
            if ($pullRequest->Number === $pullRequestPending->number) {
                continue;
            }

            $this->triggerReview($pullRequest, $pullRequestPending);
            break;
        }
    }

    private function triggerReview($pullRequest, $pullRequestPending)
    {
        $prUpsert = new \stdClass();
        $prUpsert->DeliveryId = $pullRequest->DeliveryIdText;
        $prUpsert->HookId = $pullRequest->HookId;
        $prUpsert->TargetId = $pullRequest->TargetId;
        $prUpsert->TargetType = $pullRequest->TargetType;
        $prUpsert->RepositoryOwner = $pullRequest->RepositoryOwner;
        $prUpsert->RepositoryName = $pullRequest->RepositoryName;
        $prUpsert->Id = $pullRequestPending->id;
        $prUpsert->Sender = $pullRequestPending->user->login;
        $prUpsert->Number = $pullRequestPending->number;
        $prUpsert->NodeId = $pullRequestPending->node_id;
        $prUpsert->Title = $pullRequestPending->title;
        $prUpsert->Ref = $pullRequestPending->head->ref;
        $prUpsert->InstallationId = $pullRequest->InstallationId;
        echo "Triggering review of #{$pullRequestPending->number} - Sender: " . $pullRequest->Sender . " 🔄\n";
        upsertPullRequest($prUpsert);
        $this->trace('info', 'PR {repo}#{pr}: re-queued #{next_pr} for processing', ["next_pr" => $pullRequestPending->number, "auto_merge" => ($pullRequestPending->auto_merge !== null)]);
    }

    private function handleCommentToMerge($metadata, $pullRequest, $collaboratorsLogins)
    {
        $this->commentToMerge($metadata, $pullRequest, $collaboratorsLogins, $metadata["mergeComment"], "depfu[bot]");
    }

    private function commentToMerge($metadata, $pullRequest, $collaboratorsLogins, $commentToLookup, $senderToLookup)
    {
        if ($pullRequest->Sender != $senderToLookup) {
            return;
        }

        $commentsRequest = doRequestGitHub($metadata["token"], $metadata["commentsUrl"], null, "GET");
        $comments = json_decode($commentsRequest->getBody());

        $found = false;

        foreach ($comments as $comment) {
            if (
                stripos($comment->body, $commentToLookup) !== false &&
                in_array($comment->user->login, $collaboratorsLogins)
            ) {
                $found = true;
                break;
            }
        }

        if ($found === true) {
            $this->trace('debug', 'PR {repo}#{pr}: "{comment}" already posted by a collaborator', ["comment" => $commentToLookup]);
            return;
        }

        $comment = ["body" => $commentToLookup];
        $commentResponse = doRequestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment, "POST");

        $label = ["labels" => ["☑️ auto-merge"]];
        $labelResponse = doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $label, "POST");
        $posted = [];
        $posted["comment"] = $commentToLookup;
        $posted["sender"] = $senderToLookup;
        $posted["comment_status"] = $commentResponse->getStatusCode();
        $posted["label_status"] = $labelResponse->getStatusCode();
        $this->trace('info', 'PR {repo}#{pr}: posted "{comment}" for {sender} (comment HTTP {comment_status}, label HTTP {label_status})', $posted);
    }

    private function removeIssueWipLabel($metadata, $pullRequest)
    {
        $referencedIssue = $this->getReferencedIssue($metadata, $pullRequest);

        if (
            !isset($referencedIssue->data->repository) ||
            count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0
        ) {
            $this->trace('debug', 'PR {repo}#{pr}: no linked issues — no labels to copy', []);
            return;
        }

        foreach ($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes as $node) {
            $issueNumber = $node->number;
            $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber, null, "GET");

            $issueData = json_decode($issueResponse->getBody());
            $labels = array_column($issueData->labels ?? [], "name");

            if (in_array("🛠 WIP", $labels)) {
                $url = $metadata["issuesUrl"] . "/" . $issueNumber . "/labels/🛠%20WIP";
                $response = doRequestGitHub($metadata["token"], $url, null, "DELETE");
                $this->trace('info', 'PR {repo}#{pr}: removed WIP label from linked issue #{issue} (HTTP {status})', ["issue" => $issueNumber, "status" => $response->getStatusCode()]);
            }
        }
    }

    private function getReviewsLogins($metadata)
    {
        $reviewsResponse = doRequestGitHub($metadata["token"], $metadata["reviewsUrl"], null, "GET");
        $reviews = json_decode($reviewsResponse->getBody());
        return array_map(function ($review) {
            return $review->user->login;
        }, $reviews);
    }

    private function getReferencedIssue($metadata, $pullRequest)
    {
        $referencedIssueQuery = array(
            "query" => "query {
            repository(owner: \"" . $pullRequest->RepositoryOwner . "\", name: \"" . $pullRequest->RepositoryName . "\") {
              pullRequest(number: " . $pullRequest->Number . ") {
                closingIssuesReferences(first: 10) {
                  nodes {
                      number
                  }
                }
              }
            }
          }"
        );

        $referencedIssueResponse = doRequestGitHub($metadata["token"], "graphql", $referencedIssueQuery, "POST");
        if ($referencedIssueResponse->getStatusCode() >= 300) {
            $this->trace('warning', 'PR {repo}#{pr}: failed to query linked issues (HTTP {status})', ["status" => $referencedIssueResponse->getStatusCode()]);
            return null;
        }
        return json_decode($referencedIssueResponse->getBody());
    }

    private function addLabelsFromIssue($metadata, $pullRequest, $pullRequestUpdated)
    {
        $referencedIssue = $this->getReferencedIssue($metadata, $pullRequest);

        if (
            !isset($referencedIssue->data->repository) ||
            count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0
        ) {
            return;
        }

        $labels = array();
        foreach ($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes as $node) {
            $issueNumber = $node->number;
            $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber, null, "GET");
            $issue = json_decode($issueResponse->getBody());

            $labelsIssue = array_column($issue->labels ?? [], "name");
            $position = array_search("🛠 WIP", $labelsIssue);

            if ($position !== false) {
                unset($labelsIssue[$position]);
            } else {
                $body = array("labels" => array("🛠 WIP"));
                $response = doRequestGitHub($metadata["token"], "{$metadata["issuesUrl"]}/{$issueNumber}/labels", $body, "POST");
                $this->trace('info', 'PR {repo}#{pr}: added WIP label to linked issue #{issue} (HTTP {status})', ["issue" => $issueNumber, "status" => $response->getStatusCode()]);
            }

            $labelsToNotCopy = ["🤖 bot", "good first issue", "help wanted"];

            foreach ($labelsToNotCopy as $label) {
                $position = array_search($label, $labelsIssue);

                if ($position !== false && strtolower($pullRequestUpdated->user->type) !== "bot") {
                    unset($labelsIssue[$position]);
                }
            }

            $labels = array_merge($labels, $labelsIssue);
        }

        $body = array("labels" => array_values($labels));
        $response = doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");
        $this->trace('info', 'PR {repo}#{pr}: copied labels {labels} from linked issues (HTTP {status})', ["labels" => implode(", ", array_values($labels)), "status" => $response->getStatusCode()]);
    }

    private function enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config)
    {
        global $logStream;

        $isSenderAutoMerge = in_array($pullRequest->Sender, $config->pullRequests->autoMergeSubmitters);
        if ($pullRequestUpdated->auto_merge !== null || $isSenderAutoMerge === false) {
            $skipped = [];
            $skipped["already_enabled"] = ($pullRequestUpdated->auto_merge !== null);
            $skipped["auto_merge_submitter"] = $isSenderAutoMerge;
            $this->trace('debug', 'PR {repo}#{pr}: auto-merge not enabled — already on {already_enabled}, auto-merge submitter {auto_merge_submitter}', $skipped);
        }

        if (
            $pullRequestUpdated->auto_merge == null &&
            $isSenderAutoMerge
        ) {
            $body = array(
                "query" => "mutation MyMutation {
                enablePullRequestAutoMerge(input: {pullRequestId: \"" . $pullRequest->NodeId . "\", mergeMethod: SQUASH}) {
                    clientMutationId
                     }
            }"
            );
            $autoMergeResponse = doRequestGitHub($metadata["userToken"], "graphql", $body, "POST");
            if ($autoMergeResponse->getStatusCode() < 300) {
                markPullRequestAutoMergeEnabled($pullRequest->Sequence);
                $this->trace('info', 'PR {repo}#{pr}: auto-merge (squash) enabled for submitter {sender}', ["sender" => $pullRequest->Sender]);
            }

            if ($autoMergeResponse->getStatusCode() >= 300) {
                $this->trace('warning', 'PR {repo}#{pr}: failed to enable auto-merge (HTTP {status})', ["status" => $autoMergeResponse->getStatusCode(), "sender" => $pullRequest->Sender]);
            }

            $label = array("labels" => array("☑️ auto-merge"));
            doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $label, "POST");
        }

        if ($pullRequestUpdated->mergeable_state === "clean" && $pullRequestUpdated->mergeable) {
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Enable auto merge - Is mergeable - Sender auto merge: " . ($isSenderAutoMerge ? "✅" : "⛔") . " - Sender: " . $pullRequest->Sender . " ✅\n";
            $logStream?->info(
                'PR {repo}#{pr} is mergeable — recording ready-to-merge action',
                (new LogContext(repo: "{$metadata['owner']}/{$metadata['repo']}", pr: $pullRequest->Number, mergeable_state: $pullRequestUpdated->mergeable_state, auto_merge_submitter: $isSenderAutoMerge))->toArray(),
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
            createReadyToMergeAction($pullRequest, $pullRequestUpdated);
        } else {
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Enable auto merge - Is NOT mergeable - Sender auto merge: " . ($isSenderAutoMerge ? "✅" : "⛔") . " - Sender: " . $pullRequest->Sender . " ⛔\n";
            $logStream?->warning(
                'PR {repo}#{pr} is not mergeable ({mergeable_state})',
                (new LogContext(repo: "{$metadata['owner']}/{$metadata['repo']}", pr: $pullRequest->Number, mergeable_state: $pullRequestUpdated->mergeable_state, sender: $pullRequest->Sender))->toArray(),
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
        }
    }

    private function resolveConflicts($metadata, $pullRequest, $pullRequestUpdated)
    {
        global $logStream;

        if ($pullRequestUpdated->mergeable_state !== "clean" && $pullRequestUpdated->mergeable === false) {
            if ($pullRequest->Sender !== "dependabot[bot]" && $pullRequest->Sender !== "depfu[bot]") {
                echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - Conflicts - Sender: " . $pullRequest->Sender . " ⚠️\n";
                $logStream?->warning(
                    'PR {repo}#{pr} has conflicts',
                    (new LogContext(repo: "{$metadata['owner']}/{$metadata['repo']}", pr: $pullRequest->Number, sender: $pullRequest->Sender, mergeable_state: $pullRequestUpdated->mergeable_state))->toArray(),
                    "pull_requests",
                    $pullRequest->DeliveryIdText
                );
                return;
            }
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - Recreate via bot - Sender: " . $pullRequest->Sender . " ☢️\n";

            // The marker comment being there doesn't mean the request was received: Dependabot and Depfu
            // react to a command they picked up, so one left without their reaction is posted again.
            $recreate = (new RecreateRequester())->request($metadata, $pullRequestUpdated->head->sha, $pullRequest->Sender, new DateTimeImmutable());
            $this->trace('info', 'PR {repo}#{pr}: conflicts on {sender} pull request — recreate requested: {summary}', ["sender" => $pullRequest->Sender, "summary" => $recreate->summary(), "head_sha" => $pullRequestUpdated->head->sha]);
            if ($recreate->summary() !== "") {
                echo "State: {$pullRequestUpdated->mergeable_state} - Resolve conflicts - {$recreate->summary()} - Sender: {$pullRequest->Sender} {$recreate->icon()}\n";
            }

            if ($recreate->warning() !== "") {
                $context = $recreate->context();
                $context["repo"] = "{$metadata['owner']}/{$metadata['repo']}";
                $context["sender"] = $pullRequest->Sender;
                $context["head_sha"] = $pullRequestUpdated->head->sha;
                $context["pr"] = $pullRequest->Number;
                $logStream?->warning(sprintf('PR {repo}#{pr} %s', $recreate->warning()), $context, "pull_requests", $pullRequest->DeliveryIdText);
            }
        } else {
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - No conflicts - Sender: " . $pullRequest->Sender . " 🆒\n";
            $this->trace('debug', 'PR {repo}#{pr}: no conflicts ({mergeable_state})', ["mergeable_state" => $pullRequestUpdated->mergeable_state]);
        }
    }

    /**
     * Checks if a comment containing a specific prefix exists in a pull request.
     *
     * This function fetches the first page of comments from a given pull request's
     * `commentsUrl` and searches through each comment to determine whether any
     * contains the specified prefix in its body.
     *
     * @param array  $metadata            An associative array containing metadata about the pull request.
     *                                    Must include:
     *                                    - 'commentsUrl' (string): The GitHub API URL to fetch comments.
     *                                    - 'token' (string): The GitHub API token used for authentication.
     * @param bool   $pullRequestUpdated  A boolean indicating if the pull request was updated. *(Currently unused in logic.)*
     * @param string $prefix              The string prefix to search for within the comment bodies.
     *
     * @return bool  Returns `true` if a comment containing the prefix is found, `false` otherwise.
     *
     * @throws SomeException              If the `doRequestGitHub` function or response status fails (based on actual implementation).
     */
    private function findCommentByContent($metadata, $pullRequestUpdated, $prefix): bool
    {
        $url = "{$metadata["commentsUrl"]}?per_page=100&page=1";
        $commentsResponse = doRequestGitHub($metadata["token"], $url, null, "GET");
        $commentsResponse->ensureSuccessStatus();
        $comments = json_decode($commentsResponse->getBody());

        foreach ($comments as $comment) {
            if (strpos($comment->body, $prefix) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Updates a pull request branch if it is behind the base branch.
     *
     * This function compares the base and head references of a pull request to determine
     * how many commits the head is behind. If the branch is behind, it sends a request
     * to update the pull request branch using the GitHub API.
     *
     * Diagnostic output is printed to indicate whether an update was needed and performed,
     * including mergeable state, number of commits behind, and sender info.
     *
     * @param array $metadata An associative array containing metadata required to perform the update.
     *                        Must include:
     *                        - 'token' (string): The GitHub API token.
     *                        - 'compareUrl' (string): The API base URL for comparing refs.
     *                        - 'pullRequestUrl' (string): The API URL for the pull request being updated.
     * @param object $pullRequest The queued `github_pull_requests` row, re-queued after a
     *                            successful update so the checks run on the new head commit.
     * @param object $pullRequestUpdated An object representing the updated pull request data.
     *                                   Required properties:
     *                                   - base->ref (string): The base branch name.
     *                                   - head->ref (string): The head branch name.
     *                                   - head->sha (string): The SHA of the head commit.
     *                                   - mergeable_state (string): The pull request's mergeable state.
     *                                   - user->login (string): The GitHub username of the sender.
     *
     * @return void
     *
     * @throws SomeException If the `doRequestGitHub` function fails or returns an unexpected response.
     */
    private function updateBranch($metadata, $pullRequest, $pullRequestUpdated)
    {
        $baseRef = urlencode($pullRequestUpdated->base->ref);
        $headRef = urlencode($pullRequestUpdated->head->ref);

        $compareResponse = doRequestGitHub($metadata["token"], "{$metadata["compareUrl"]}{$baseRef}...{$headRef}", null, "GET");
        if ($compareResponse->getStatusCode() >= 300) {
            $this->trace('warning', 'PR {repo}#{pr}: failed to compare {base}...{head} (HTTP {status})', ["base" => $pullRequestUpdated->base->ref, "head" => $pullRequestUpdated->head->ref, "status" => $compareResponse->getStatusCode()]);
            return;
        }
        $compare = json_decode($compareResponse->getBody());

        if ($compare->behind_by === 0) {
            echo "State: {$pullRequestUpdated->mergeable_state} - Commits Behind: 0 - Updating branch: No - Sender: {$pullRequestUpdated->user->login} 👎🏻\n";
            $this->trace('debug', 'PR {repo}#{pr}: branch up to date with {base} — no update needed', ["base" => $pullRequestUpdated->base->ref, "ahead_by" => ($compare->ahead_by ?? null)]);
            return;
        }

        echo "State: {$pullRequestUpdated->mergeable_state} - Commits Behind: {$compare->behind_by} - Updating branch: Yes - Sender: {$pullRequestUpdated->user->login} 👍🏻\n";
        $url = $metadata["pullRequestUrl"] . "/update-branch";
        $body = array("expected_head_sha" => $pullRequestUpdated->head->sha);
        $updateResponse = doRequestGitHub($metadata["token"], $url, $body, "PUT");
        $updateLevel = 'warning';
        if ($updateResponse->getStatusCode() === 202) {
            $updateLevel = 'info';
        }

        $update = [];
        $update["behind_by"] = $compare->behind_by;
        $update["base"] = $pullRequestUpdated->base->ref;
        $update["head_sha"] = $pullRequestUpdated->head->sha;
        $update["status"] = $updateResponse->getStatusCode();
        $this->trace($updateLevel, 'PR {repo}#{pr}: branch {behind_by} commit(s) behind {base} — update requested (HTTP {status})', $update);
        if ($updateResponse->getStatusCode() === 202) {
            $this->requeueAfterBranchUpdate($metadata, $pullRequest, $pullRequestUpdated);
        }
    }

    /**
     * Re-queues the pull request once its branch was updated. The merge commit created
     * by update-branch is authored by the bot, and the synchronize event for it isn't
     * processed, so the bot re-queues the pull request itself to get the checks running
     * on the new head commit. update-branch is asynchronous, so it waits for the new head
     * first: otherwise the worker could pick the pull request up on the old head and
     * update the branch again.
     *
     * @param array  $metadata           Metadata for the GitHub API request.
     * @param object $pullRequest        The queued `github_pull_requests` row.
     * @param object $pullRequestUpdated The pull request as fetched before the update.
     *
     * @return void
     */
    private function requeueAfterBranchUpdate(array $metadata, object $pullRequest, object $pullRequestUpdated): void
    {
        $watcher = new PullRequestHeadWatcher(sleep(...));
        $newHead = $watcher->waitForNewHead($metadata["token"], $metadata["pullRequestUrl"], $pullRequestUpdated->head->sha);
        if ($newHead === null) {
            echo "State: Branch update requested but head still at {$pullRequestUpdated->head->sha} - not re-queuing ⚠️\n";
            $this->trace('warning', 'PR {repo}#{pr}: branch update requested but head still at {head_sha} — not re-queuing', ["head_sha" => $pullRequestUpdated->head->sha]);
            return;
        }

        upsertPullRequest($pullRequest);
        echo "State: Branch updated - new head {$newHead} - re-queued for checks 🔁\n";
        $this->trace('info', 'PR {repo}#{pr}: branch updated to {new_head} — re-queued for checks', ["old_head" => $pullRequestUpdated->head->sha, "new_head" => $newHead]);
    }
}
