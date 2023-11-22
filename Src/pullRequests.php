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

    if($pullRequestUpdated->assignee != null){
        $urlAssignees = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/issues/" . $pullRequest->PullRequestNumber . "/assignees";
        $body = array(
            "assignees" => array("guibranco")
        );
        requestGitHub($token, $urlAssignees, $body);
    }

    $urlReview = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/pulls/" . $pullRequest->PullRequestNumber . "/reviews";
    $body = array("event" => "APPROVE");
    requestGitHub($token, $urlReview, $body);

    if (in_array($pullRequest->PullRequestSubmitter, $config->pullRequests->autoReviewSubmitters)) {
        $body = array(
            "event" => "APPROVE",
            "body" => "Automatically approved by [" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")"
        );
        requestGitHub($gitHubUserToken, $urlReview, $body);
    }

    if (in_array($pullRequest->PullRequestSubmitter, $config->pullRequests->autoMergeSubmitters)) {
        $body = array(
            "query" => "mutation MyMutation {
            enablePullRequestAutoMerge(input: {pullRequestId: \"" . $pullRequest->NodeId . "\", mergeMethod: SQUASH}) {
                clientMutationId
                 }
        }"
        );
        requestGitHub($gitHubUserToken, "graphql", $body);
    }

    if ($pullRequest->PullRequestSubmitter == "dependabot[bot]") {
        $urlComment = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/issues/" . $pullRequest->PullRequestNumber . "/comments";
        $comment = array("body" => "@dependabot squash and merge");
        requestGitHub($gitHubUserToken, $urlComment, $comment);
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
