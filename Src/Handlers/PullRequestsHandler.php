<?php

namespace GuiBranco\GStracciniBot\Handlers;

use GuiBranco\GStracciniBot\Library\DependencyFileLabelService;
use GuiBranco\GStracciniBot\Library\MarkdownGroupCheckboxValidator;
use GuiBranco\GStracciniBot\Library\PullRequestCodeScanner;
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
    public function handleItem($pullRequest, $isRetry = false)
    {
        global $logStream;

        if (!$isRetry) {
            echo "https://github.com/{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}/pull/{$pullRequest->Number}:\n\n";
            $logStream?->info(
                "Processing pull request #{$pullRequest->Number}",
                ['repo' => "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}", 'sender' => $pullRequest->Sender],
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
        }

        $config = loadConfig();
        $token = generateInstallationToken($pullRequest->InstallationId, $pullRequest->RepositoryName);
        $metadata = $this->createMetadata($token, $pullRequest, $config);
        $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
        try {
            $pullRequestResponse->ensureSuccessStatus();
        } catch (\Exception $e) {
            echo "Failed to fetch PR #{$pullRequest->Number}: {$e->getMessage()} ❌\n";
            $logStream?->error(
                "Failed to fetch pull request data: {$e->getMessage()}",
                ['repo' => "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}", 'pr' => $pullRequest->Number],
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
            return;
        }
        $pullRequestUpdated = json_decode($pullRequestResponse->getBody());

        if ($pullRequestUpdated->state === "closed") {
            $logStream?->info(
                "PR #{$pullRequest->Number} is closed — cleaning up labels",
                ['repo' => "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}"],
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
            $this->removeIssueWipLabel($metadata, $pullRequest);
            $this->removeLabels($metadata, $pullRequestUpdated);
            $this->checkForOtherPullRequests($metadata, $pullRequest);
            removeUnreadActionsForPullRequest($pullRequestUpdated->node_id);

            if ($pullRequestUpdated->merged) {
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
                "PR #{$pullRequest->Number} state is {$pullRequestUpdated->state} — skipping",
                ['repo' => "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}", 'state' => $pullRequestUpdated->state],
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
                "PR #{$pullRequest->Number} has unknown mergeable state — retrying",
                ['repo' => "{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}", 'sender' => $pullRequest->Sender],
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
            $this->handleItem($pullRequest, true);
            return;
        }

        $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, "pull request");
        $this->enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config);
        $this->addLabelsFromIssue($metadata, $pullRequest, $pullRequestUpdated);
        $this->updateBranch($metadata, $pullRequestUpdated);

        $labelsToAdd = [];
        if (strtolower($pullRequestUpdated->user->type) === "bot") {
            $labelsToAdd[] = "🤖 bot";
        }

        $collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
        $collaboratorsLogins = array_column(json_decode($collaboratorsResponse->getBody()), "login");

        $botReviewed = false;
        $invokerReviewed = false;

        $reviewsLogins = $this->getReviewsLogins($metadata);
        if (in_array($config->botName . "[bot]", $reviewsLogins)) {
            $botReviewed = true;
        }

        $intersections = array_intersect($reviewsLogins, $collaboratorsLogins);

        if (count($intersections) > 0) {
            $invokerReviewed = true;
        }

        if ($pullRequestUpdated->assignee == null) {
            $body = array("assignees" => $collaboratorsLogins);
            doRequestGitHub($metadata["token"], $metadata["assigneesUrl"], $body, "POST");
        }

        if (!$botReviewed) {
            $body = array("event" => "APPROVE");
            doRequestGitHub($metadata["token"], $metadata["reviewsUrl"], $body, "POST");
        }

        $autoReview = in_array($pullRequest->Sender, $config->pullRequests->autoReviewSubmitters);
        $iAmTheOwner = in_array($config->ownerHandler, $collaboratorsLogins);

        if (!$invokerReviewed && $autoReview && $iAmTheOwner) {
            $bodyMsg = "Automatically approved by " . $metadata["botNameMarkdown"];
            $body = array("event" => "APPROVE", "body" => $bodyMsg);
            doRequestGitHub($metadata["userToken"], $metadata["reviewsUrl"], $body, "POST");
        }

        if (!$invokerReviewed && !$autoReview) {
            $reviewers = $collaboratorsLogins;
            if (in_array($pullRequest->Sender, $reviewers)) {
                $reviewers = array_values(array_diff($reviewers, array($pullRequest->Sender)));
            }
            if (count($reviewers) > 0) {
                $body = array("reviewers" => $reviewers);
                doRequestGitHub($metadata["token"], $metadata["requestReviewUrl"], $body, "POST");
            }

            if (!in_array($pullRequest->Sender, $collaboratorsLogins)) {
                $labelsToAdd[] = "🚦 awaiting triage";
            }
        }

        $this->removeAwaitingTriageIfReviewed($metadata, $pullRequestUpdated, $collaboratorsLogins, $reviewsLogins, $pullRequest->Sender);

        if (!empty($labelsToAdd)) {
            $body = array("labels" => $labelsToAdd);
            doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");
        }

        if ($iAmTheOwner) {
            $this->resolveConflicts($metadata, $pullRequest, $pullRequestUpdated);
            $this->handleCommentToMerge($metadata, $pullRequest, $collaboratorsLogins);
        }

        $versionBumpResolved = $this->checkVersionBump($metadata, $pullRequestUpdated);

        $this->checkPullRequestDescription($metadata, $pullRequestUpdated);
        $this->checkPullRequestContent($metadata, $pullRequestUpdated);
        $this->checkDependencyChanges($metadata, $pullRequestUpdated);

        if ($versionBumpResolved) {
            setCheckRunSucceeded($metadata, $checkRunId, "pull request");
        } else {
            echo "State: Version bump decision pending - leaving 'pull request' check run in progress ⏳\n";
        }
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
            return true;
        }

        $labels = array_column($pullRequestUpdated->labels ?? [], "name");
        if (!$analyzer->looksLikeFeature($pullRequestUpdated->title, $pullRequestUpdated->head->ref, $labels)) {
            return true;
        }

        if (!$this->findCommentByContent($metadata, $pullRequestUpdated, VersionBumpCommentBuilder::MARKER)) {
            $builder = new VersionBumpCommentBuilder();
            $body = $builder->build($pullRequestUpdated->title);
            doRequestGitHub($metadata["token"], $metadata["commentsUrl"], array("body" => $body), "POST");
        }

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
        if (!in_array("🚦 awaiting triage", $currentLabels)) {
            return;
        }

        if (in_array($sender, $collaboratorsLogins)) {
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
            return;
        }

        $labelToRemove = str_replace(" ", "%20", "🚦 awaiting triage");
        $url = $metadata["pullRequestUrl"] . "/labels/{$labelToRemove}";
        $response = doRequestGitHub($metadata["token"], $url, null, "DELETE");

        if ($response->getStatusCode() < 300) {
            echo "Awaiting triage: Label removed - All reviewers have reviewed and last review is recent ✅\n";
        } else {
            echo "Awaiting triage: Failed to remove label (HTTP {$response->getStatusCode()}) ❌\n";
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
        doRequestGitHub(
            $metadata["token"],
            $metadata["pullRequestUrl"],
            array("body" => $content),
            "PATCH"
        );

        if (strpos($content, 'Please provide a description') !== false) {
            $comment = array("body" => $content);
            doRequestGitHub($metadata["token"], $metadata["commentsUrl"], $comment, "POST");
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
    private function checkPullRequestDescription($metadata, $pullRequestUpdated)
    {
        $type = "pull request description";
        $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, $type);
        $bodyLength = isset($pullRequestUpdated->body) ? strlen($pullRequestUpdated->body) : 0;
        if ($bodyLength === 0) {
            $templateContent = $this->getPullRequestTemplate($metadata);
            if ($templateContent) {
                $this->updatePullRequestDescription($metadata, $templateContent);
                return;
            }

            $defaultMessage = "Please provide a description for this pull request.";
            $this->updatePullRequestDescription($metadata, $defaultMessage);
            setCheckRunFailed($metadata, $checkRunId, $type, $defaultMessage);
            return;
        }

        if ($bodyLength <= 250) {
            setCheckRunFailed($metadata, $checkRunId, $type, "Pull request description too short: {$bodyLength} characters (at least 250 characters long required).");
            return;
        }

        $validator = new MarkdownGroupCheckboxValidator();
        $validationResult = $validator->validateCheckboxes($pullRequestUpdated->body);
        if (isset($validationResult['errors']) && !empty($validationResult['errors'])) {
            $message = $validator->generateReport($validationResult);
            setCheckRunFailed($metadata, $checkRunId, $type, $message);
            return;
        } elseif ($validationResult["found"] === false || $validationResult["found"] === 0) {
            setCheckRunSucceeded($metadata, $checkRunId, $type, "No groups or checkboxes found in the PR body.");
            return;
        }

        setCheckRunSucceeded($metadata, $checkRunId, $type);
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
            return;
        }

        setCheckRunSucceeded($metadata, $checkRunId, $type, $report);
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
            return;
        }

        $labelsToAdd = ["📦 dependencies"];

        foreach (array_keys($detectedDependencies) as $label) {
            $labelsToAdd[] = $label;
        }

        $body = array("labels" => $labelsToAdd);
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");

        $report = "Detected dependency changes for package managers: " . implode(", ", array_values($detectedDependencies));
        setCheckRunSucceeded($metadata, $checkRunId, $type, $report);
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
            $label = str_replace(" ", "%20", $label);
            $url = $metadata["pullRequestUrl"] . "/labels/{$label}";
            doRequestGitHub($metadata["token"], $url, null, "DELETE");
        }
    }

    private function checkForOtherPullRequests($metadata, $pullRequest)
    {
        $pullRequestsOpenResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestsUrl"] . "?state=open&sort=created", null, "GET");
        $pullRequestsOpen = json_decode($pullRequestsOpenResponse->getBody());
        $any = false;

        if (count($pullRequestsOpen) === 0) {
            echo "No other pull requests to review ❌\n";
            return;
        }

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

        if (!$found) {
            $comment = array("body" => $commentToLookup);
            doRequestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment, "POST");

            $label = array("labels" => array("☑️ auto-merge"));
            doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $label, "POST");
        }
    }

    private function removeIssueWipLabel($metadata, $pullRequest)
    {
        $referencedIssue = $this->getReferencedIssue($metadata, $pullRequest);

        if (
            !isset($referencedIssue->data->repository) ||
            count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0
        ) {
            return;
        }

        foreach ($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes as $node) {
            $issueNumber = $node->number;
            $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber, null, "GET");

            $issueData = json_decode($issueResponse->getBody());
            $labels = array_column($issueData->labels ?? [], "name");

            if (in_array("🛠 WIP", $labels)) {
                $url = $metadata["issuesUrl"] . "/" . $issueNumber . "/labels/🛠%20WIP";
                doRequestGitHub($metadata["token"], $url, null, "DELETE");
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
                doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber . "/labels", $body, "POST");
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
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");
    }

    private function enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config)
    {
        global $logStream;

        $isSenderAutoMerge = in_array($pullRequest->Sender, $config->pullRequests->autoMergeSubmitters);
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
            }

            $label = array("labels" => array("☑️ auto-merge"));
            doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $label, "POST");
        }

        if ($pullRequestUpdated->mergeable_state === "clean" && $pullRequestUpdated->mergeable) {
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Enable auto merge - Is mergeable - Sender auto merge: " . ($isSenderAutoMerge ? "✅" : "⛔") . " - Sender: " . $pullRequest->Sender . " ✅\n";
            $logStream?->info(
                "PR #{$pullRequest->Number} is mergeable — recording ready-to-merge action",
                ['repo' => "{$metadata['owner']}/{$metadata['repo']}", 'mergeable_state' => $pullRequestUpdated->mergeable_state, 'auto_merge_submitter' => $isSenderAutoMerge],
                "pull_requests",
                $pullRequest->DeliveryIdText
            );
            createReadyToMergeAction($pullRequest, $pullRequestUpdated);
        } else {
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Enable auto merge - Is NOT mergeable - Sender auto merge: " . ($isSenderAutoMerge ? "✅" : "⛔") . " - Sender: " . $pullRequest->Sender . " ⛔\n";
            $logStream?->warning(
                "PR #{$pullRequest->Number} is not mergeable",
                ['repo' => "{$metadata['owner']}/{$metadata['repo']}", 'mergeable_state' => $pullRequestUpdated->mergeable_state, 'sender' => $pullRequest->Sender],
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
                    "PR #{$pullRequest->Number} has conflicts",
                    ['repo' => "{$metadata['owner']}/{$metadata['repo']}", 'sender' => $pullRequest->Sender, 'mergeable_state' => $pullRequestUpdated->mergeable_state],
                    "pull_requests",
                    $pullRequest->DeliveryIdText
                );
                return;
            }
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - Recreate via bot - Sender: " . $pullRequest->Sender . " ☢️\n";

            $prefix = "<!--GStraccini:{$pullRequestUpdated->head->sha}-->\n";
            $commentExists = $this->findCommentByContent($metadata, $pullRequestUpdated, $prefix);

            if ($commentExists) {
                echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - Already requested to recreate - Sender: " . $pullRequest->Sender . " ⚠️\n";
                return;
            }

            if ($pullRequest->Sender === "dependabot[bot]") {
                $comment = array("body" => "{$prefix}@dependabot recreate");
            } else {
                $comment = array("body" => "{$prefix}@depfu recreate");
            }

            doRequestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment, "POST");
        } else {
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - No conflicts - Sender: " . $pullRequest->Sender . " 🆒\n";
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
    private function updateBranch($metadata, $pullRequestUpdated)
    {
        $baseRef = urlencode($pullRequestUpdated->base->ref);
        $headRef = urlencode($pullRequestUpdated->head->ref);

        $compareResponse = doRequestGitHub($metadata["token"], "{$metadata["compareUrl"]}{$baseRef}...{$headRef}", null, "GET");
        if ($compareResponse->getStatusCode() >= 300) {
            return;
        }
        $compare = json_decode($compareResponse->getBody());

        if ($compare->behind_by === 0) {
            echo "State: {$pullRequestUpdated->mergeable_state} - Commits Behind: 0 - Updating branch: No - Sender: {$pullRequestUpdated->user->login} 👎🏻\n";
            return;
        }

        echo "State: {$pullRequestUpdated->mergeable_state} - Commits Behind: {$compare->behind_by} - Updating branch: Yes - Sender: {$pullRequestUpdated->user->login} 👍🏻\n";
        $url = $metadata["pullRequestUrl"] . "/update-branch";
        $body = array("expected_head_sha" => $pullRequestUpdated->head->sha);
        doRequestGitHub($metadata["token"], $url, $body, "PUT");
    }
}
