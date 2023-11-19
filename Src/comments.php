<?php

require_once "vendor/autoload.php";
require_once "config.php";
require_once "lib/functions.php";
require_once "lib/database.php";
require_once "lib/github.php";

function handleComment($comment)
{
    $config = loadConfig();

    if ($comment->CommentUser === $config->botName . "[bot]") {
        return;
    }

    $metadata = array(
        "token" => generateInstallationToken($comment->InstallationId, $comment->RepositoryName),
        "reactionUrl" => "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/issues/comments/" . $comment->CommentId . "/reactions",
        "commentUrl" => "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/issues/" . $comment->IssueNumber . "/comments"
    );

    if (!in_array($comment->CommentUser, $config->allowedInvokers)) {
        requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"));
        return;
    }

    $executedAtLeastOne = false;

    foreach ($config->commands as $command) {
        $commandExpression = "@" . $config->botName . " " . $command->command;
        if (strpos($commandExpression, strtolower($comment->CommentBody)) !== false) {
            $executedAtLeastOne = true;
            $method = "execute_" . toCamelCase($command->command);
            $method($config, $metadata, $comment);
        }
    }

    if (!$executedAtLeastOne) {
        requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "I'm sorry @" . $comment->CommentUser . ", I can't do that."));
        requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"));
    }
}

function execute_helloWorld($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "heart"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Hello @" . $comment->CommentUser . "!"));
}

function execute_thankYou($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "You're welcome @" . $comment->CommentUser . "!"));
}

function execute_help($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"));
    $helpComment = "That's what I can do:\r\n";
    foreach ($config->commands as $command) {
        $helpComment .= "- `@" . $config->botName . " " . $command->command . "`: " . $command->description . "\r\n";
    }
    $helpComment .= "\r\n\r\nMultiple commands can be issued at same time, just respect each command pattern (with bot name prefix + command).\r\nIf you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.\r\n";
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $helpComment));
}

function execute_fixCsproj($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"));
}

function execute_csharpier($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"));

    $pullRequest = requestGitHub($metadata["token"], "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/pulls/" . $comment->IssueNumber);

    $pullRequestBody = json_decode($pullRequest["body"]);
    $branch = $pullRequestBody->head->ref;

    $permissions =  array("metadata" => "read", "contents" => "write", "pull_requests" => "write", "actions" => "write");

    $tokenBot = generateInstallationToken($config->botRepositoryInstallationId, $config->botRepository, $permissions);
    $url = "repos/" . $config->botRepository . "/actions/workflows/csharpier.yml/dispatches";
    $data = array(
        "ref" => "main",
        "inputs" => array(
            "owner" => $comment->RepositoryOwner,
            "repository" => $comment->RepositoryName,
            "branch" => $branch,
            "pull_request" => $comment->IssueNumber,
            "installationId" => $comment->InstallationId
        )
    );
    requestGitHub($tokenBot, $url, $data);
}

function main()
{
    $comments = readComments();
    foreach ($comments as $comment) {
        handleComment($comment);
        updateComment($comment->Sequence);
    }
}

sendHealthCheck("/start");
main();
sendHealthCheck();
