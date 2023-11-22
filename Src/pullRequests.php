<?php

require_once "vendor/autoload.php";
require_once "config.php";
require_once "lib/functions.php";
require_once "lib/database.php";
require_once "lib/github.php";

function handlePullRequest($pullRequest)
{
    global $gitHubUserToken;
    $config = loadConfig();

    $token = generateInstallationToken($pullRequest->InstallationId, $pullRequest->RepositoryName);

    $pullRequestResponse = requestGitHub($token, "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/pulls/" . $pullRequest->PullRequestNumber);
    $pullRequestUpdated = json_decode($pullRequestResponse["body"]);

    if ($pullRequestUpdated->state != "open") {
        return;
    }

    $reviewsResponse = requestGitHub($token, "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/pulls/" . $pullRequest->PullRequestNumber . "/reviews");
    $reviews = json_decode($reviewsResponse["body"]);

    $botReviewed = false;
    $invokerReviewed = false;

    foreach ($reviews as $review) {
        if ($review->user->login == $config->botName . "[bot]") {
            $botReviewed = true;
            continue;
        }

        if (in_array($review->user->login, $config->allowedInvokers)) {
            $invokerReviewed = true;
            if ($botReviewed) {
                break;
            }
        }
    }

    if ($pullRequestUpdated->assignee == null) {
        $urlAssignees = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/issues/" . $pullRequest->PullRequestNumber . "/assignees";
        $body = array(
            "assignees" => array("guibranco")
        );
        requestGitHub($token, $urlAssignees, $body);
    }

    if (!$botReviewed) {
        $urlReview = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/pulls/" . $pullRequest->PullRequestNumber . "/reviews";
        $body = array("event" => "APPROVE");
        requestGitHub($token, $urlReview, $body);
    }

    if (!$invokerReviewed && in_array($pullRequest->PullRequestSubmitter, $config->pullRequests->autoReviewSubmitters)) {
        $body = array(
            "event" => "APPROVE",
            "body" => "Automatically approved by [" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")"
        );
        requestGitHub($gitHubUserToken, $urlReview, $body);
    }

    if ($pullRequestUpdated->auto_merge == null && in_array($pullRequest->PullRequestSubmitter, $config->pullRequests->autoMergeSubmitters)) {
        $body = array(
            "query" => "mutation MyMutation {
            enablePullRequestAutoMerge(input: {pullRequestId: \"" . $pullRequest->NodeId . "\", mergeMethod: SQUASH}) {
                clientMutationId
                 }
        }"
        );
        requestGitHub($gitHubUserToken, "graphql", $body);
    }

    if ($pullRequest->PullRequestSubmitter == "dependabot[bot]" && in_array($pullRequest->RepositoryOwner, $config->pullRequests->allowedSquashAndMergeOwners)) {
        $commentsRequest = requestGitHub($token, "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/issues/" . $pullRequest->PullRequestNumber . "/comments");
        $comments = json_decode($commentsRequest["body"]);

        $found = false;

        foreach ($comments as $comment) {
            if ($comment->body == "@dependabot squash and merge") {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $urlComment = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/issues/" . $pullRequest->PullRequestNumber . "/comments";
            $comment = array("body" => "@dependabot squash and merge");
            requestGitHub($gitHubUserToken, $urlComment, $comment);
        }
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

sendHealthCheck($healthChecksIoPullRequests, "/start");
main();
sendHealthCheck($healthChecksIoPullRequests);
