<?php

require_once "vendor/autoload.php";
require_once "config/config.php";

function handleComment($comment)
{
    $config = loadConfig();

    if ($comment->CommentSender === $config->botName . "[bot]") {
        return;
    }

    $metadata = array(
        "token" => generateInstallationToken($comment->InstallationId, $comment->RepositoryName),
        "reactionUrl" => "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/issues/comments/" . $comment->CommentId . "/reactions",
        "pullRequestUrl" => "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/pulls/" . $comment->PullRequestNumber,
        "commentUrl" => "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/issues/" . $comment->PullRequestNumber . "/comments"
    );

    $collaboratorUrl = "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName . "/collaborators/" . $comment->CommentSender;
    $collaboratorResponse = requestGitHub($metadata["token"], $collaboratorUrl);
    if ($collaboratorResponse["status"] === 404) {
        requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"));
        requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "I'm sorry @" . $comment->CommentSender . ", I can't do that, you aren't a collaborator. :pleading_face:"));
        return;
    }

    $executedAtLeastOne = false;

    foreach ($config->commands as $command) {
        $commandExpression = "@" . $config->botName . " " . $command->command;
        if (stripos($comment->CommentBody, $commandExpression) !== false) {
            $executedAtLeastOne = true;
            $method = "execute_" . toCamelCase($command->command);
            $method($config, $metadata, $comment);
        }
    }

    if (!$executedAtLeastOne) {
        requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "I'm sorry @" . $comment->CommentSender . ", I can't do that. :pleading_face:"));
        requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"));
    }
}

function execute_hello($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "heart"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Hello @" . $comment->CommentSender . "! :wave:"));
}

function execute_thankYou($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "You're welcome @" . $comment->CommentSender . "! :pray:"));
}

function execute_help($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"));
    $helpComment = "That's what I can do :neckbeard::\r\n";
    foreach ($config->commands as $command) {
        $parameters = "";
        $parametersHelp = "";
        $inDevelopment = isset($command->dev) && $command->dev ? " :warning: (in development - maybe not working as expected!)" : "";
        if (isset($command->parameters)) {
            foreach ($command->parameters as $parameter) {
                $parameters .= " <" . $parameter->parameter . ">";
                $parametersHelp .= "\t- `" . $parameter->parameter . "`: `[" .
                    ($parameter->required ? "required" : "optional") . "]` " .
                    $parameter->description . "\r\n";
            }
        }
        $helpComment .= "- `@" . $config->botName . " " . $command->command . $parameters . "`: " . $command->description . $inDevelopment . "\r\n";
        $helpComment .= $parametersHelp;
    }
    $helpComment .= "\r\n\r\nMultiple commands can be issued at the same time, just respect each command pattern (with bot name prefix + command).\r\n\r\n" .
        "> **Warning**\r\n> \r\n> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.\r\n";
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $helpComment));
}

function execute_appveyor($config, $metadata, $comment)
{
    $pullRequestResponse = requestGitHub($metadata["token"], $metadata["pullRequestUrl"]);
    $pullRequest = json_decode($pullRequestResponse["body"]);

    if ($pullRequest->state != "open") {
        requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"));
        requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "This pull request is not open anymore! :no_entry:"));
        return;
    }

    preg_match("/@" . $config->botName . "\sappveyor(?:\s(commit|pull request))?/", $comment->CommentBody, $matches);

    $searchSlug = strtolower($comment->RepositoryOwner . "/" . $comment->RepositoryName);

    $projectsResponse = requestAppVeyor("projects");
    $projects = json_decode($projectsResponse["body"]);
    $projects = array_filter($projects, function ($p) use ($searchSlug) {
        return $searchSlug === strtolower($p->repositoryName);
    });
    $projects = array_values($projects);

    $data = array(
        "accountName" => $projects[0]->accountName,
        "projectSlug" => $projects[0]->slug
    );

    if (count($matches) === 2 && $matches[1] === "commit") {
        requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"));
        $data["branch"] = $pullRequest->head->ref;
        $data["commitId"] = $pullRequest->head->sha;
    } elseif (count($matches) === 2 && $matches[1] === "pull request") {
        requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"));
        $data["pullRequestId"] = $comment->PullRequestNumber;
    } else {
        requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"));
        requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "I'm sorry @" . $comment->CommentSender . ", I can't do that, invalid type parameter. :pleading_face:"));
        return;
    }

    $buildResponse = requestAppVeyor("builds", $data);
    $build = json_decode($buildResponse["body"]);
    $buildId = $build->buildId;
    $version = $build->version;
    $link = "https://ci.appveyor.com/project/" .
        $projects[0]->accountName . "/" . $projects[0]->slug .
        "/builds/" . $buildId;
    $commentBody = "AppVeyor build started! :rocket:\r\n\r\n" .
        "Build ID: [" . $buildId . "](" . $link . ")\r\n" .
        "Version: " . $version . "\r\n";
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody));
}

function execute_bumpVersion($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Bumping [.NET version](https://dotnet.microsoft.com/en-us/platform/support/policy/dotnet-core) on this branch! :arrow_heading_up:"));
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
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Fixing [NuGet packages](https://nuget.org) references in .csproj files! :pill:"));
    callWorkflow($config, $metadata, $comment, "fix-csproj.yml");
}

function execute_prettier($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Running [Prettier](https://prettier.io/) on this branch! :wrench:"));
    callWorkflow($config, $metadata, $comment, "prettier.yml");
}

function execute_review($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"));

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
    $pullRequest->Sender = $comment->PullRequestSender;
    $pullRequest->Number = $comment->PullRequestNumber;
    $pullRequest->NodeId = $comment->PullRequestNodeId;
    $pullRequest->Title = $pullRequestUpdated->title;
    $pullRequest->Ref = $pullRequestUpdated->head->ref;
    $pullRequest->InstallationId = $comment->InstallationId;

    upsertPullRequest($pullRequest);
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Review enabled! :eyes:"));
}

function execute_track($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Tracking this pull request! :repeat:"));
    callWorkflow($config, $metadata, $comment, "track.yml");
}

function execute_updateSnapshot($config, $metadata, $comment)
{
    requestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"));
    requestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Updating test snapshots"));
    callWorkflow($config, $metadata, $comment, "update-test-snapshot.yml");
}

function callWorkflow($config, $metadata, $comment, $workflow)
{
    $pullRequestResponse = requestGitHub($metadata["token"], $metadata["pullRequestUrl"]);
    $pullRequest = json_decode($pullRequestResponse["body"]);

    $tokenBot = generateInstallationToken($config->botRepositoryInstallationId, $config->botRepository);
    $url = "repos/" . $config->botRepository . "/actions/workflows/" . $workflow . "/dispatches";
    $data = array(
        "ref" => "main",
        "inputs" => array(
            "owner" => $comment->RepositoryOwner,
            "repository" => $comment->RepositoryName,
            "branch" => $pullRequest->head->ref,
            "pull_request" => $comment->PullRequestNumber,
            "installationId" => $comment->InstallationId
        )
    );
    requestGitHub($tokenBot, $url, $data);
}

function main()
{
    $comments = readTable("github_pull_requests_comments");
    foreach ($comments as $comment) {
        handleComment($comment);
        updateTable("github_pull_requests_comments", $comment->Sequence);
    }
}

sendHealthCheck($healthChecksIoComments, "/start");
main();
sendHealthCheck($healthChecksIoComments);
