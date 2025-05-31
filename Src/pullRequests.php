<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\MarkdownGroupCheckboxValidator;
use GuiBranco\GStracciniBot\Library\DependencyFileLabelService;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\GStracciniBot\Library\PullRequestCodeScanner;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

define("ISSUES", "/issues/");
define("PULLS", "/pulls/");

function handleItem($pullRequest, $isRetry = false)
{
    if (!$isRetry) {
        echo "https://github.com/{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}/pull/{$pullRequest->Number}:\n\n";
    }

    $config = loadConfig();
    $token = generateInstallationToken($pullRequest->InstallationId, $pullRequest->RepositoryName);
    $metadata = createMetadata($token, $pullRequest, $config);
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestResponse->ensureSuccessStatus();
    $pullRequestUpdated = json_decode($pullRequestResponse->getBody());

    if ($pullRequestUpdated->state === "closed") {
        removeIssueWipLabel($metadata, $pullRequest);
        removeLabels($metadata, $pullRequestUpdated);
        checkForOtherPullRequests($metadata, $pullRequest);
    }

    if ($pullRequestUpdated->state != "open") {
        echo "PR State: {$pullRequestUpdated->state} ⛔\n";
        if ($pullRequest->State !== "CLOSED") {
            updateStateToClosedInTable("pull_requests", $pullRequest->Sequence);
        }

        return;
    }

    if ($isRetry === false && $pullRequestUpdated->mergeable_state === "unknown") {
        sleep(5);
        echo "State: {$pullRequestUpdated->mergeable_state} - Retrying #{$pullRequestUpdated->number} - Sender: " . $pullRequest->Sender . " 🔄\n";
        handleItem($pullRequest, true);
        return;
    }

    $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, "pull request");
    enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config);
    addLabelsFromIssue($metadata, $pullRequest, $pullRequestUpdated);
    updateBranch($metadata, $pullRequestUpdated);

    $labelsToAdd = [];
    if (strtolower($pullRequestUpdated->user->type) === "bot") {
        $labelsToAdd[] = "🤖 bot";
    }

    $collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
    $collaboratorsLogins = array_column(json_decode($collaboratorsResponse->getBody()), "login");

    $botReviewed = false;
    $invokerReviewed = false;

    $reviewsLogins = getReviewsLogins($metadata);
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

    if (!empty($labelsToAdd)) {
        $body = array("labels" => $labelsToAdd);
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");
    }

    if ($iAmTheOwner) {
        resolveConflicts($metadata, $pullRequest, $pullRequestUpdated);
        handleCommentToMerge($metadata, $pullRequest, $collaboratorsLogins);
    }

    checkPullRequestDescription($metadata, $pullRequestUpdated);
    checkPullRequestContent($metadata, $pullRequestUpdated);
    checkDependencyChanges($metadata, $pullRequestUpdated);
    setCheckRunSucceeded($metadata, $checkRunId, "pull request");
}

function createMetadata($token, $pullRequest, $config)
/**
 * Creates metadata required for performing GitHub API requests.
 *
 * @param string $token The GitHub API token.
 * @param object $pullRequest The pull request object containing details such as RepositoryOwner, RepositoryName, and Number.
 * @param object $config The configuration object containing settings like botName and dashboardUrl.
 *
 * @return array An associative array with API endpoints and tokens for further requests.
 */
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
        "squashAndMergeComment" => "@dependabot squash and merge",
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
function getPullRequestTemplate($metadata)
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

    $template = searchTemplateInRepository($metadata, $metadata['repo'], $allPaths);
    if ($template !== null) {
        return $template;
    }

    $template = searchTemplateInRepository($metadata, '.github', $allPaths);
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
 * @param array $paths List of file paths to check for template content
 * @return string|null The decoded template content if found, null otherwise.
 */
function searchTemplateInRepository($metadata, $repoName, $paths)
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
        } catch (Exception $e) {
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
function updatePullRequestDescription($metadata, $content)
{
    doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], array("body" => $content), "PATCH");

    if (strpos($content, 'Please provide a description') !== false) {
        $comment = array("body" => $content);
        doRequestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment, "POST");
    }
}

/**
 * Checks the pull request description for compliance with the required standards.
 * @return void
 * If the description is missing or too short, it applies a template or default message.
 * Validates the presence of groups and checkboxes in the description.
 *
 * @param array $metadata Metadata for the GitHub API request
 * @param object  $pullRequestUpdated The updated pull request data
 */
function checkPullRequestDescription($metadata, $pullRequestUpdated)
{
    $type = "pull request description";
    $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, $type);
    $bodyLength = isset($pullRequestUpdated->body) ? strlen($pullRequestUpdated->body) : 0;
    if ($bodyLength === 0) {
        $templateContent = getPullRequestTemplate($metadata);
        if ($templateContent) {
            updatePullRequestDescription($metadata, $templateContent);
            return;
        }

        $defaultMessage = "Please provide a description for this pull request.";
        updatePullRequestDescription($metadata, $defaultMessage);
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

function checkPullRequestContent($metadata, $pullRequestUpdated)
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
function checkDependencyChanges($metadata, $pullRequestUpdated): void
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

    foreach (array_values($detectedDependencies) as $packageManager) {
        $labelsToAdd[] = $packageManager;
    }

    $body = array("labels" => $labelsToAdd);
    doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");

    $report = "Detected dependency changes for package managers: " . implode(", ", array_values($detectedDependencies));
    setCheckRunSucceeded($metadata, $checkRunId, $type, $report);
}

function removeLabels($metadata, $pullRequestUpdated)
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

function checkForOtherPullRequests($metadata, $pullRequest)
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
            triggerReview($pullRequest, $pullRequestPending);
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

        triggerReview($pullRequest, $pullRequestPending);
        break;
    }
}

function triggerReview($pullRequest, $pullRequestPending)
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

function handleCommentToMerge($metadata, $pullRequest, $collaboratorsLogins)
{
    commentToMerge($metadata, $pullRequest, $collaboratorsLogins, $metadata["squashAndMergeComment"], "dependabot[bot]");
    commentToMerge($metadata, $pullRequest, $collaboratorsLogins, $metadata["mergeComment"], "depfu[bot]");
}

function commentToMerge($metadata, $pullRequest, $collaboratorsLogins, $commentToLookup, $senderToLookup)
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

function removeIssueWipLabel($metadata, $pullRequest)
{
    $referencedIssue = getReferencedIssue($metadata, $pullRequest);

    if (
        !isset($referencedIssue->data->repository) ||
        count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0
    ) {
        return;
    }

    foreach ($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes as $node) {
        $issueNumber = $node->number;
        $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber, null, "GET");

        $labels = array_column(json_decode($issueResponse->getBody())->labels, "name");

        if (in_array("🛠 WIP", $labels)) {
            $url = $metadata["issuesUrl"] . "/" . $issueNumber . "/labels/🛠%20WIP";
            doRequestGitHub($metadata["token"], $url, null, "DELETE");
        }
    }
}

function getReviewsLogins($metadata)
{
    $reviewsResponse = doRequestGitHub($metadata["token"], $metadata["reviewsUrl"], null, "GET");
    $reviews = json_decode($reviewsResponse->getBody());
    return array_map(function ($review) {
        return $review->user->login;
    }, $reviews);
}

function getReferencedIssue($metadata, $pullRequest)
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

function addLabelsFromIssue($metadata, $pullRequest, $pullRequestUpdated)
{
    $referencedIssue = getReferencedIssue($metadata, $pullRequest);

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

        $labelsIssue = array_column($issue->labels, "name");
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

function enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config)
{
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
        doRequestGitHub($metadata["userToken"], "graphql", $body, "POST");

        $label = array("labels" => array("☑️ auto-merge"));
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $label, "POST");
    }

    if ($pullRequestUpdated->mergeable_state === "clean" && $pullRequestUpdated->mergeable) {
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Enable auto merge - Is mergeable - Sender auto merge: " . ($isSenderAutoMerge ? "✅" : "⛔") . " - Sender: " . $pullRequest->Sender . " ✅\n";
        $comment = array("body" => "<!-- gstraccini-bot:ready-merge -->\nThis pull request is ready ✅ for merge/squash.");
        doRequestGitHub($metadata["token"], $metadata["commentsUrl"], $comment, "POST");
        //$body = array("merge_method" => "squash", "commit_title" => $pullRequest->Title);
        //requestGitHub($metadata["token"], $metadata["pullRequestUrl"] . "/merge", $body);
    } else {
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Enable auto merge - Is NOT mergeable - Sender auto merge: " . ($isSenderAutoMerge ? "✅" : "⛔") . " - Sender: " . $pullRequest->Sender . " ⛔\n";
    }
}

function resolveConflicts($metadata, $pullRequest, $pullRequestUpdated)
{
    if ($pullRequestUpdated->mergeable_state !== "clean" && $pullRequestUpdated->mergeable === false) {
        if ($pullRequest->Sender !== "dependabot[bot]" && $pullRequest->Sender !== "depfu[bot]") {
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - Conflicts - Sender: " . $pullRequest->Sender . " ⚠️\n";
            return;
        }
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve conflicts - Recreate via bot - Sender: " . $pullRequest->Sender . " ☢️\n";

        $prefix = "<!--GStraccini:{$pullRequestUpdated->head->sha}-->\n";
        $commentExists = findCommentByContent($metadata, $pullRequestUpdated, $prefix);

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
function findCommentByContent($metadata, $pullRequestUpdated, $prefix): bool
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
function updateBranch($metadata, $pullRequestUpdated)
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

$healthCheck = new HealthChecks($healthChecksIoPullRequests, GUIDv4::random());
$processor = new ProcessingManager("pull_requests", $healthCheck, $logger);
$processor->initialize("handleItem", 55);
