<?php

require_once "vendor/autoload.php";
require_once "config/config.php";

function handleComment($comment)
{
    $config = loadConfig();

    if ($comment->CommentUser === $config->botName . "[bot]") {
        return;
    }

    $metadata = array(
        "token" => generateInstallationToken($comment->InstallationId, $comment->RepositoryName),
        "reactionUrl" => "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/issues/comments/" . $comment->CommentId . "/reactions",
        "pullRequestUrl" => "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/pulls/" . $comment->IssueNumber,
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
        requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "I'm sorry @" . $comment->CommentUser . ", I can't do that. :pleading_face:"));
        requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"));
    }
}

function execute_helloWorld($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "heart"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Hello @" . $comment->CommentUser . "! :wave:"));
}

function execute_thankYou($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "You're welcome @" . $comment->CommentUser . "! :pray:"));
}

function execute_help($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"));
    $helpComment = "That's what I can do :neckbeard::\r\n";
    foreach ($config->commands as $command) {
        $parameters = "";
        $parametersHelp = "";
        if (isset($command->parameters)) {
            foreach ($command->parameters as $parameter) {
                $parameters .= " <" . $parameter->parameter . ">";
                $parametersHelp .= "\t- `" . $parameter->parameter . "`: `[" . ($parameter->required ? "required" : "optional") . "]` " . $parameter->description . "\r\n";
            }
        }
        $helpComment .= "- `@" . $config->botName . " " . $command->command . $parameters . "`: " . $command->description . "\r\n";
        $helpComment .= $parametersHelp;
    }
    $helpComment .= "\r\n\r\nMultiple commands can be issued at the same time, just respect each command pattern (with bot name prefix + command).\r\n\r\n> **Warning**\r\n> \r\n> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.\r\n> The allowed invokers are configurable via the `config.json` file.";
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $helpComment));
}

function execute_bumpVersion($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Bumping .NET version on this branch! :arrow_heading_up:\r\n\r\n:warning: Experimental - Not working!"));
    callWorkflow($config, $metadata, $comment, "bump-version.yml");
}

function execute_csharpier($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Running [CSharpier](https://csharpier.com/) on this branch! :wrench:"));
    callWorkflow($config, $metadata, $comment, "csharpier.yml");
}

function execute_fixCsproj($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Fixing .cjproj NuGet packages! :pill:\r\n\r\n:warning: Experimental - Not working!"));
    callWorkflow($config, $metadata, $comment, "fix-csproj.yml");
}

function execute_review($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"));
    
    // TODO: remove this workaround when https://github.com/guibranco/webhooks/issues/77 is done
    $pullRequestResponse = requestGitHub($metadata["token"], $metadata["pullRequestUrl"]);
    $pullRequestUpdated = json_decode($pullRequestResponse["body"]);

    $pullRequest = new \stdClass();
    $pullRequest->DeliveryId = $comment->DeliveryIdText;
    $pullRequest->HookId = $comment->HookId;
    $pullRequest->TargetId = $comment->TargetId;
    $pullRequest->TargetType = $comment->TargetType;    
    $pullRequest->RepositoryOwner = $comment->RepositoryOwner;
    $pullRequest->RepositoryName = $comment->RepositoryName;
    $pullRequest->Id = $pullRequestUpdated->id;
    $pullRequest->Submitter = $pullRequestUpdated->user->login;
    $pullRequest->Number = $pullRequestUpdated->number;
    $pullRequest->NodeId = $pullRequestUpdated->node_id;
    $pullRequest->Title = $pullRequestUpdated->title;
    $pullRequest->Ref = $pullRequestUpdated->head->ref;
    $pullRequest->InstallationId = $comment->InstallationId;
    upsertPullRequest($pullRequest);
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Review enabled! :guide_dog:"));
}

function execute_track($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Tracking this pull request! :repeat:\r\n\r\n:warning: Experimental - Not working!"));
    callWorkflow($config, $metadata, $comment, "track.yml");
}

function callWorkflow($config, $metadata, $comment, $workflow)
{
    // TODO: remove this workaround when https://github.com/guibranco/webhooks/issues/76 is done
    $pullRequestResponse = requestGitHub($metadata["token"], "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/pulls/" . $comment->IssueNumber);
    $pullRequest = json_decode($pullRequestResponse["body"]);

    $tokenBot = generateInstallationToken($config->botRepositoryInstallationId, $config->botRepository);
    $url = "repos/" . $config->botRepository . "/actions/workflows/" . $workflow . "/dispatches";
    $data = array(
        "ref" => "main",
        "inputs" => array(
            "owner" => $comment->RepositoryOwner,
            "repository" => $comment->RepositoryName,
            "branch" => $pullRequest->head->ref, //$comment->Branch,
            "pull_request" => $comment->IssueNumber,
            "installationId" => $comment->InstallationId
        )
    );
    requestGitHub($tokenBot, $url, $data);
}

function main()
{
    $comments = readTable("github_comments");
    foreach ($comments as $comment) {
        handleComment($comment);
        updateTable("github_comments", $comment->Sequence);
    }
}

sendHealthCheck($healthChecksIoComments, "/start");
main();
sendHealthCheck($healthChecksIoComments);
