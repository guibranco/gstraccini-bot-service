<?php

require_once "vendor/autoload.php";
require_once "config/config.php";

define("ISSUES", "/issues/");
define("PULLS", "/pulls/");

function handlePullRequest($pullRequest)
{
    global $gitHubUserToken;
    $config = loadConfig();

    $token = generateInstallationToken($pullRequest->InstallationId, $pullRequest->RepositoryName);
    $repoPrefix = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName;
    $metadata = array(
        "token" => $token,
        "userToken" => $gitHubUserToken,
        "squashAndMergeComment" => "@dependabot squash and merge",
        "commentsUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/comments",
        "pullRequestUrl" => $repoPrefix . PULLS . $pullRequest->Number,
        "reviewsUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/reviews",
        "assigneesUrl" => $repoPrefix . ISSUES . $pullRequest->Number . "/assignees",
        "collaboratorsUrl" => $repoPrefix . "/collaborators",
        "requestReviewUrl" => $repoPrefix . PULLS . $pullRequest->Number . "/requested_reviewers",
        "issuesUrl" => $repoPrefix . "/issues",
        "botNameMarkdown" => "[" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")"
    );

    $pullRequestResponse = requestGitHub($metadata["token"], $metadata["pullRequestUrl"]);
    $pullRequestUpdated = json_decode($pullRequestResponse["body"]);

    if ($pullRequestUpdated->state == "closed") {
        removeIssueWipLabel($metadata, $pullRequest);
    }

    if ($pullRequestUpdated->state != "open") {
        return;
    }

    $reviewsLogins = getReviewsLogins($metadata);

    $collaboratorsResponse = requestGitHub($metadata["token"], $metadata["collaboratorsUrl"]);
    $collaborators = json_decode($collaboratorsResponse["body"]);
    $collaboratorsLogins = array_column($collaborators, "login");

    $botReviewed = false;
    $invokerReviewed = false;

    if (in_array($config->botName . "[bot]", $reviewsLogins)) {
        $botReviewed = true;
    }

    $intersections = array_intersect($reviewsLogins, $collaboratorsLogins);

    if (count($intersections) > 0) {
        $invokerReviewed = true;
    }

    if ($pullRequestUpdated->assignee == null) {
        $body = array("assignees" => $collaboratorsLogins);
        requestGitHub($metadata["token"], $metadata["assigneesUrl"], $body);
    }

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
        requestGitHub($metadata["userToken"], "graphql", $body);
    }

    addLabels($metadata, $pullRequest);

    if (!$botReviewed) {
        $body = array("event" => "APPROVE");
        requestGitHub($metadata["token"], $metadata["reviewsUrl"], $body);
    }

    $autoReview = in_array($pullRequest->Sender, $config->pullRequests->autoReviewSubmitters);

    if (!$invokerReviewed && $autoReview) {
        $bodyMsg = "Automatically approved by " . $metadata["botNameMarkdown"];
        $body = array("event" => "APPROVE", "body" => $bodyMsg);
        requestGitHub($metadata["userToken"], $metadata["reviewsUrl"], $body);
    }

    if (!$invokerReviewed && !$autoReview) {
        $reviewers = $collaboratorsLogins;
        if (in_array($pullRequest->Sender, $reviewers)) {
            $reviewers = array_diff($reviewers, array($pullRequest->Sender));
        }
        if (count($reviewers) > 0) {
            $body = array("reviewers" => $reviewers);
            requestGitHub($metadata["token"], $metadata["requestReviewUrl"], $body);
        }
    }

    commentToDependabot($metadata, $pullRequest, $collaboratorsLogins);
}

function commentToDependabot($metadata, $pullRequest, $collaboratorsLogins)
{
    if ($pullRequest->Sender != "dependabot[bot]") {
        return;
    }

    $commentsRequest = requestGitHub($metadata["token"], $metadata["commentsUrl"]);
    $comments = json_decode($commentsRequest["body"]);

    $found = false;

    foreach ($comments as $comment) {
        if (
            stripos($comment->body, $metadata["botNameMarkdown"]) !== false &&
            in_array($comment->user->login, $collaboratorsLogins)
        ) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        $comment = array("body" => "Thanks for the pull request, " . $metadata["botNameMarkdown"]);
        requestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment);
    }

}

function removeIssueWipLabel($metadata, $pullRequest)
{
    echo "Removing WIP label from pull request " . $pullRequest->Number . "\n";
}

function getReviewsLogins($metadata)
{

    $reviewsResponse = requestGitHub($metadata["token"], $metadata["reviewsUrl"]);
    $reviews = json_decode($reviewsResponse["body"]);
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

    $referencedIssueResponse = requestGitHub($metadata["token"], "graphql", $referencedIssueQuery);
    return json_decode($referencedIssueResponse["body"]);
}

function addLabels($metadata, $pullRequest)
{
    $referencedIssue = getReferencedIssue($metadata, $pullRequest);

    if (count($referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes) == 0) {
        return;
    }

    $issueNumber = $referencedIssue->data->repository->pullRequest->closingIssuesReferences->nodes[0]->number;
    $issueResponse = requestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber);

    $labels = array_column(json_decode($issueResponse["body"])->labels, "name");
    $body = array("labels" => array("WIP"));
    requestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $issueNumber . "/labels", $body);

    $body = array("labels" => $labels);
    requestGitHub($metadata["token"], $metadata["issuesUrl"] . "/" . $pullRequest->Number . "/labels", $body);
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
