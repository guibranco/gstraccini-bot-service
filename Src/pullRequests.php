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

    $botDashboardUrl = "https://gstraccini.bot/dashboard";
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
        "pullRequestsUrl" => $repoPrefix . "/pulls",
        "reviewsUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/reviews",
        "assigneesUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/assignees",
        "collaboratorsUrl" => $repoPrefix . "/collaborators",
        "requestReviewUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/requested_reviewers",
        "checkRunUrl" => $repoPrefix . "/check-runs",
        "issuesUrl" => $repoPrefix . "/issues",
        "labelsUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/labels",
        "botNameMarkdown" => "[" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")",
        "dashboardUrl" => $botDashboardUrl . $prQueryString
    );

    echo "https://github.com/{$pullRequest->RepositoryOwner}/{$pullRequest->RepositoryName}/pull/{$pullRequest->Number}:\n\n";

    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestUpdated = json_decode($pullRequestResponse->body);

    if ($pullRequestUpdated->state == "closed") {
        removeIssueWipLabel($metadata, $pullRequest);
        checkForOtherPullRequests($metadata, $pullRequest);
    }

    if ($pullRequestUpdated->state != "open") {
        return;
    }

    $checkRunId = setCheckRunInProgress($metadata, $pullRequestUpdated->head->sha, "pull request");
    enableAutoMerge($metadata, $pullRequest, $pullRequestUpdated, $config);
    addLabels($metadata, $pullRequest);
    updateBranch($metadata, $pullRequestUpdated);

    $labelsToAdd = [];
    if (strtolower($pullRequestUpdated->user->type) === "bot") {
        $labelsToAdd[] = "🤖 bot";
    }

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
            $labelsToAdd[] = "🚦awaiting triage";
        }
    }

    if (count($labelsToAdd) > 0) {
        $body = array("labels" => $labelsToAdd);
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");
    }

    if ($iAmTheOwner) {
        resolveConflicts($metadata, $pullRequest, $pullRequestUpdated);
        handleCommentToMerge($metadata, $pullRequest, $collaboratorsLogins);
    }

    setCheckRunCompleted($metadata, $checkRunId, "pull request");
}

function checkForOtherPullRequests($metadata, $pullRequest)
{
    $pullRequestsOpenResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestsUrl"] . "?state=open&sort=created", null, "GET");
    $pullRequestsOpen = json_decode($pullRequestsOpenResponse->body);

    foreach ($pullRequestsOpen as $pullRequestPending) {

        if ($pullRequest->Number === $pullRequestPending->number) {
            continue;
        }

        if ($pullRequestPending->auto_merge !== null) {
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
            upsertPullRequest($prUpsert);
            echo "State: {$pullReqestPending->mergeable_state} - Triggering review of #{$pullRequestPending->number} - Sender: " . $pullRequest->Sender . " ✅\n";
            break;
        }
    }
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

        $label = array("labels" => array("☑️ auto-merge"));
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $label, "POST");
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
        $url = $metadata["labelsUrl"] . "/🛠 WIP";
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
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");
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
        $comment = array("body" => "This pull request is ready ✅ for merge/squash.");
        doRequestGitHub($metadata["token"], $metadata["commentsUrl"], $comment, "POST");
        //$body = array("merge_method" => "squash", "commit_title" => $pullRequest->Title);
        //requestGitHub($metadata["token"], $metadata["pullRequestUrl"] . "/merge", $body);
    } else {
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Enable auto merge - Is NOT mergeable - Sender auto merge: " . ($isSenderAutoMerge ? "✅" : "⛔") . " - Sender: " . $pullRequest->Sender . " ⛔\n";
    }
}

function resolveConflicts($metadata, $pullRequest, $pullRequestUpdated)
{
    if ($pullRequestUpdated->mergeable_state != "clean" && !$pullRequestUpdated->mergeable) {
        if ($pullRequest->Sender !== "dependabot[bot]" && $pullRequest->Sender !== "depfu[bot]") {
            echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve Conflicts - Is NOT mergeable - Sender: " . $pullRequest->Sender . " ⛔\n";
            return;
        }
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve Conflicts - Recreate - Sender: " . $pullRequest->Sender . " ☢️\n";

        if ($pullRequest->Sender !== "dependabot[bot]") {
            $comment = array("body" => "@dependabot recreate");
        } else {
            $comment = array("body" => "@depfu recreate");
        }

        doRequestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment, "POST");
    } else {
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Resolve Conflicts - No conflicts - Sender: " . $pullRequest->Sender . " ⚠️\n";
    }
}

function updateBranch($metadata, $pullRequestUpdated)
{
    if ($pullRequestUpdated->mergeable_state === "behind" ||
        $pullRequestUpdated->mergeable_state === "unknown ") {
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Update branch - Updating branch - Sender: " . $pullRequestUpdated->user->login . " ⚠️\n";
        $url = $metadata["pullRequestUrl"] . "/update-branch";
        $body = array("expected_head_sha" => $pullRequestUpdated->head->sha);
        doRequestGitHub($metadata["token"], $url, $body, "PUT");
    } else {
        echo "State: " . $pullRequestUpdated->mergeable_state . " - Update branch - NOT updating branch - Sender: " . $pullRequestUpdated->user->login . " ⛔\n";
    }
}

function main()
{
    $pullRequests = readTable("github_pull_requests");
    foreach ($pullRequests as $pullRequest) {
        echo str_repeat("=-", 50) . "=\n";
        handlePullRequest($pullRequest);
        updateTable("github_pull_requests", $pullRequest->Sequence);
        echo str_repeat("=-", 50) . "=\n";
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
