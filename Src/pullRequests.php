<?php

require_once "config/config.php";

use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

define("ISSUES", "/issues/");
define("PULLS", "/pulls/");

function handlePullRequest($pullRequest)
{
    global $gitHubUserToken;
    $config = loadConfig();

    $botDashboardUrl = "https://bot.straccini.com/dashboard";
    $prQueryString =
        "?owner=" . $pullRequest->RepositoryOwner .
        "&repo=" . $pullRequest->RepositoryName .
        "&pullRequest=" . $pullRequest->Number;

    $token = generateInstallationToken($pullRequest->InstallationId, $pullRequest->RepositoryName);
    $repoPrefix = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName;
    $metadata = array(
        "token" => $token,
        "userToken" => $gitHubUserToken,
        "squashAndMergeComment" => "@dependabot squash and merge",
        "mergeComment" => "@depfu merge",
        "commentsUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/comments",
        "pullRequestUrl" => $repoPrefix . PULLS . $pullRequest->Number,
        "reviewsUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/reviews",
        "assigneesUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/assignees",
        "collaboratorsUrl" => $repoPrefix . "/collaborators",
        "requestReviewUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/requested_reviewers",
        "checkRunUrl" => $repoPrefix . "/check-runs",
        "issuesUrl" => $repoPrefix . "/issues",
        "botNameMarkdown" => "[" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")",
        "dashboardUrl" => $botDashboardUrl . $prQueryString
    );

    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestUpdated = json_decode($pullRequestResponse->body);

    if ($pullRequestUpdated->state == "closed") {
        removeIssueWipLabel($metadata, $pullRequest);
    }

    if ($pullRequestUpdated->state != "open") {
        return;
    }

    $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, "pull request");
    enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config);
    addLabels($metadata, $pullRequest);
    updateBranch($metadata, $pullRequestUpdated);

    $collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
    $collaboratorsLogins = array_column(json_decode($collaboratorsResponse->body), "login");

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

    if (!$invokerReviewed && $autoReview) {
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
    }

    if (in_array("guibranco", $collaboratorsLogins)) {
        commentToMerge(
            $metadata,
            $pullRequest,
            $collaboratorsLogins,
            $metadata["squashAndMergeComment"],
            "dependabot[bot]"
        );
        commentToMerge($metadata, $pullRequest, $collaboratorsLogins, $metadata["mergeComment"], "depfu[bot]");
        resolveConflicts($metadata, $pullRequest, $pullRequestUpdated);
    }
    setCheckRunCompleted($metadata, $checkRunId, "pull request");
}

function commentToMerge($metadata, $pullRequest, $collaboratorsLogins, $commentToLookup, $senderToLookup)
{
    if ($pullRequest->Sender != $senderToLookup) {
        return;
    }

    $commentsRequest = doRequestGitHub($metadata["token"], $metadata["commentsUrl"], null, "GET");
    $comments = json_decode($commentsRequest->body);

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
    }
}

function removeIssueWipLabel($metadata, $pullRequest)
{
    $referencedIssue = getReferencedIssue($metadata, $pullRequest);

    if (count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0) {
        return;
    }

    $issueNumber = $referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes[0]->number;
    $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber, null, "GET");

    $labels = array_column(json_decode($issueResponse->body)->labels, "name");
    if (in_array("🛠 WIP", $labels)) {
        $url = $metadata["issuesUrl"] . "/" . $issueNumber . "/labels/🛠 WIP";
        doRequestGitHub($metadata["token"], $url, null, "DELETE");
    }
}

function getReviewsLogins($metadata)
{
    $reviewsResponse = doRequestGitHub($metadata["token"], $metadata["reviewsUrl"], null, "GET");
    $reviews = json_decode($reviewsResponse->body);
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
            closingIssuesReferences(first: 1) {
              nodes {
                  number
              }
            }
          }
        }
      }"
    );

    $referencedIssueResponse = doRequestGitHub($metadata["token"], "graphql", $referencedIssueQuery, "POST");
    return json_decode($referencedIssueResponse->body);
}

function addLabels($metadata, $pullRequest)
{
    $referencedIssue = getReferencedIssue($metadata, $pullRequest);

    if (count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0) {
        return;
    }

    $issueNumber = $referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes[0]->number;
    $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber, null, "GET");

    $labels = array_column(json_decode($issueResponse->body)->labels, "name");

    $position = array_search("🛠 WIP", $labels);

    if ($position !== false) {
        unset($labels[$position]);
    } else {
        $body = array("labels" => array("🛠 WIP"));
        doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber . "/labels", $body, "POST");
    }

    $body = array("labels" => array_values($labels));
    doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $pullRequest->Number . "/labels", $body, "POST");
}

function enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config)
{
    if (
        $pullRequestUpdated->auto_merge == null &&
        in_array($pullRequest->Sender, $config->pullRequests->autoMergeSubmitters)
    ) {
        $body = array(
            "query" => "mutation MyMutation {
            enablePullRequestAutoMerge(input: {pullRequestId: \"" . $pullRequest->NodeId . "\", mergeMethod: SQUASH}) {
                clientMutationId
                 }
        }"
        );
        doRequestGitHub($metadata["userToken"], "graphql", $body, "POST");
    }

    if ($pullRequestUpdated->mergeable_state == "clean" && $pullRequestUpdated->mergeable) {
        echo "Pull request " . $pullRequestUpdated->number . " of " .
            $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . " is mergeable - Sender: " . $pullRequest->Sender . "\n";
        //     $body = array("merge_method" => "squash", "commit_title" => $pullRequest->Title);
        //     requestGitHub($metadata["token"], $metadata["pullRequestUrl"] . "/merge", $body);
    }
}

$collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
$collaboratorsLogins = array_column(json_decode($collaboratorsResponse->body), "login");
$labels = [];
if (!in_array($pullRequest->Sender, $collaboratorsLogins)) {
    $labels[] = "\ud83d\udea6awaiting triage";
}
$userResponse = doRequestGitHub($metadata["token"], "users/" . $pullRequest->Sender, null, "GET");
$userDetails = json_decode($userResponse->body);
if ($userDetails->type === "Bot") {
    $labels[] = "\ud83e\udd16 bot";
}
if (in_array($pullRequest->Sender, $config->pullRequests->autoMergeSubmitters)) {
    $labels[] = "\u2611\ufe0f auto-merge";
}
if (count($labels) > 0) {
    $body = array("labels" => $labels);
    doRequestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $pullRequest->Number . "/labels", $body, "POST");
}
function resolveConflicts($metadata, $pullRequest, $pullRequestUpdated)
{
    if ($pullRequestUpdated->mergeable_state != "clean" && !$pullRequestUpdated->mergeable) {
        if ($pullRequest->Sender != "dependabot[bot]") {
            echo "Pull request " . $pullRequestUpdated->number . " of " .
                $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . " is NOT mergeable - Sender: " . $pullRequest->Sender . "\n";
            return;
        }
        $comment = array("body" => "@dependabot recreate");
        doRequestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment, "POST");
    }
}

function updateBranch($metadata, $pullRequestUpdated)
{
    if ($pullRequestUpdated->mergeable_state == "behind") {
        $url = $metadata["pullRequestUrl"] . "/update-branch";
        $body = array("expected_head_sha" => $pullRequestUpdated->head->sha);
        doRequestGitHub($metadata["token"], $url, $body, "PUT");
    }
}

function main()
{
    $pullRequests = readTable("github_pull_requests");
    foreach ($pullRequests as $pullRequest) {
        handlePullRequest($pullRequest);
        updateTable("github_pull_requests", $pullRequest->Sequence);
    }
}

$healthCheck = new HealthChecks($healthChecksIoPullRequests, GUIDv4::random());
$healthCheck->start();
$time = time();
while (true) {
    main();
    $limit = ($time + 55);
    if ($limit < time()) {
        break;
    }
}
$healthCheck->end();
