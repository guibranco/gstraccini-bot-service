<?php

require_once "config/config.php";

use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleComment($comment)
{
    $config = loadConfig();

    if ($comment->CommentSender === $config->botName . "[bot]") {
        return;
    }

    $prefix = "I'm sorry @" . $comment->CommentSender;
    $suffix = ", I can't do that.";
    $emoji = " :pleading_face:";

    $repoPrefix = "repos/" . $comment->RepositoryOwner . "/" . $comment->RepositoryName;
    $metadata = array(
        "token" => generateInstallationToken($comment->InstallationId, $comment->RepositoryName),
        "repoPrefix" => $repoPrefix,
        "reactionUrl" => $repoPrefix . "/issues/comments/" . $comment->CommentId . "/reactions",
        "pullRequestUrl" => $repoPrefix . "/pulls/" . $comment->PullRequestNumber,
        "commentUrl" => $repoPrefix . "/issues/" . $comment->PullRequestNumber . "/comments",
        "errorMessages" => array(
            "notCollaborator" => $prefix . $suffix . " You aren't a collaborator in this repository." . $emoji,
            "invalidParameter" => $prefix . $suffix . " Invalid parameter." . $emoji,
            "notOpen" => $prefix . $suffix . " This pull request is no longer open. :no_entry:",
            "notAllowed" => $prefix . $suffix . " You aren't allowed to use this bot." . $emoji,
            "commandNotFound" => $prefix . $suffix . " Command not found." . $emoji,
            "notImplemented" => $prefix . $suffix . " Feature not implemented yet." . $emoji
        )
    );

    if ($comment->CommentSender === "github-actions[bot]") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        return;
    }

    $collaboratorUrl = $repoPrefix . "/collaborators/" . $comment->CommentSender;
    $collaboratorResponse = doRequestGitHub($metadata["token"], $collaboratorUrl, null, "GET");
    if ($collaboratorResponse->statusCode === 404) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["notCollaborator"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    $executedAtLeastOne = false;

    $pullRequestIsOpen = checkIfPullRequestIsOpen($metadata);

    foreach ($config->commands as $command) {
        $commandExpression = "@" . $config->botName . " " . $command->command;
        if (stripos($comment->CommentBody, $commandExpression) !== false) {
            $executedAtLeastOne = true;
            if (isset($command->requiresPullRequestOpen) && $command->requiresPullRequestOpen && !$pullRequestIsOpen) {
                doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
                $body = $metadata["errorMessages"]["notOpen"];
                doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
                continue;
            }
            $method = "execute_" . toCamelCase($command->command);
            $method($config, $metadata, $comment);
        }
    }

    if (!$executedAtLeastOne) {
        $body = $metadata["errorMessages"]["commandNotFound"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
    }
}

function execute_hello($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "heart"), "POST");
    $body = "Hello @" . $comment->CommentSender . "! :wave:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
}

function execute_thankYou($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"), "POST");
    $body = "You're welcome @" . $comment->CommentSender . "! :pray:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
}

function execute_help($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
    $helpComment = "That's what I can do :neckbeard::\r\n";
    foreach ($config->commands as $command) {
        $parameters = "";
        $parametersHelp = "";
        $inDevelopment = isset($command->dev) && $command->dev
            ? " :warning: (in development - maybe not working as expected!)"
            : "";
        if (isset($command->parameters)) {
            foreach ($command->parameters as $parameter) {
                $parameters .= " <" . $parameter->parameter . ">";
                $parametersHelp .= "\t- `" . $parameter->parameter . "`: `[" .
                    ($parameter->required ? "required" : "optional") . "]` " .
                    $parameter->description . "\r\n";
            }
        }
        $helpComment .= "- `@" . $config->botName . " " . $command->command . $parameters . "`: ";
        $helpComment .= $command->description . $inDevelopment . "\r\n";
        $helpComment .= $parametersHelp;
    }
    $helpComment .= "\r\n\r\nMultiple commands can be issued at the same time. " .
        "Just respect each command pattern (with bot name prefix + command).\r\n\r\n" .
        "> **Warning**\r\n> \r\n" .
        "> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.\r\n";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $helpComment), "POST");
}

function execute_appveyorBuild($config, $metadata, $comment)
{
    preg_match(
        "/@" . $config->botName . "\sappveyor\sbuild(?:\s(commit|pull request))?/",
        $comment->CommentBody,
        $matches
    );

    $project = getAppVeyorProject($metadata, $comment);

    if ($project == null) {
        return;
    }

    $data = array(
        "accountName" => $project->accountName,
        "projectSlug" => $project->slug
    );

    if (count($matches) === 2 && $matches[1] === "commit") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
        $data["branch"] = $metadata["headRef"];
        $data["commitId"] = $metadata["headSha"];
    } elseif (count($matches) === 2 && $matches[1] === "pull request") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
        $data["pullRequestId"] = $comment->PullRequestNumber;
    } else {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["invalidParameter"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return;
    }

    $buildResponse = requestAppVeyor("builds", $data);
    if ($buildResponse->statusCode !== 200) {
        $commentBody = "AppVeyor build failed: :x:\r\n\r\n```\r\n" . $buildResponse->body . "\r\n```\r\n";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
        return;
    }
    $build = json_decode($buildResponse->body);
    $buildId = $build->buildId;
    $version = $build->version;
    $link = "https://ci.appveyor.com/project/" . $project->accountName . "/" . $project->slug . "/builds/" . $buildId;
    $commentBody = "AppVeyor build (" . $matches[1] . ") started! :rocket:\r\n\r\n" .
        "Build ID: [" . $buildId . "](" . $link . ")\r\n" .
        "Version: " . $version . "\r\n";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
}

function execute_appveyorBumpVersion($config, $metadata, $comment)
{
    preg_match(
        "/@" . $config->botName . "\sappveyor\sbump\sversion(?:\s(major|minor|build))?/",
        $comment->CommentBody,
        $matches
    );

    $project = getAppVeyorProject($metadata, $comment);

    if ($project == null) {
        return;
    }

    $url = "projects/" . $project->accountName . "/" . $project->slug . "/settings";
    $settingsResponse = requestAppVeyor($url);
    if ($settingsResponse->statusCode !== 200) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $commentBody = "AppVeyor bump version failed: :x:\r\n\r\n```\r\n" . $settingsResponse->body . "\r\n```\r\n";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
        return;
    }

    $settings = json_decode($settingsResponse->body);

    if (count($matches) === 2 && $matches[1] === "build") {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
        updateNextBuildNumber($metadata, $project, $settings->settings->nextBuildNumber + 1);
    } elseif (count($matches) === 2 && ($matches[1] === "minor" || $matches[1] === "major")) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["notImplemented"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    } else {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $metadata["errorMessages"]["invalidParameter"];
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    }
}

function execute_appveyorRegister($config, $metadata, $comment)
{
    $data = array(
        "repositoryProvider" => "gitHub",
        "repositoryName" => $comment->RepositoryOwner . "/" . $comment->RepositoryName,
    );
    $registerResponse = requestAppVeyor("projects", $data);
    if ($registerResponse->statusCode !== 200) {
        $commentBody = "AppVeyor registration failed: :x:\r\n\r\n```\r\n" . $registerResponse->body . "\r\n```\r\n";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
        return;
    }
    $register = json_decode($registerResponse->body);

    $link = "https://ci.appveyor.com/project/" .
        $register->accountName . "/" . $register->slug;
    $commentBody = "AppVeyor registered! :rocket:\r\n\r\n" .
        "Project ID: [" . $register->projectId . "](" . $link . ")\r\n" .
        "Slug: " . $register->slug . "\r\n";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
}

function execute_appveyorReset($config, $metadata, $comment)
{
    $project = getAppVeyorProject($metadata, $comment);

    if ($project == null) {
        return;
    }

    updateNextBuildNumber($metadata, $project, 0);
}

function execute_bumpVersion($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $dotNetLink = "https://dotnet.microsoft.com/en-us/platform/support/policy/dotnet-core";
    $body = "Bumping [.NET version](" . $dotNetLink . ") on this branch! :arrow_heading_up:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "bump-version.yml");
}

function execute_csharpier($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $body = "Running [CSharpier](https://csharpier.com/) on this branch! :wrench:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "csharpier.yml");
}

function execute_fixCsproj($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
    $body = "Fixing [NuGet packages](https://nuget.org) references in .csproj files! :pill:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "fix-csproj.yml");
}

function execute_prettier($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $body = "Running [Prettier](https://prettier.io/) on this branch! :wrench:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    callWorkflow($config, $metadata, $comment, "prettier.yml");
}

function execute_rerunFailedChecks($config, $metadata, $comment)
{
    $filter = function ($checkRun) {
        return $checkRun->conclusion === "failure" && $checkRun->status === "completed" && $checkRun->app->slug !== "github-actions";
    };
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestUpdated = json_decode($pullRequestResponse->body);
    $commitSha1 = $pullRequestUpdated->head->sha;
    $checkRunsResponse = doRequestGitHub($metadata["token"], $metadata["repoPrefix"] . "/commits/" . $commitSha1 . "/check-runs?status=completed", null, "GET");
    $checkRuns = json_decode($checkRunsResponse->body);
    $failedCheckRuns = array_filter($checkRuns->check_runs, $filter);
    $total = count($failedCheckRuns);

    $body = "Rerunning " . $total . " failed check" . ($total === 1 ? "" : "s") . " on the commit `" . $commitSha1 . "`! :repeat:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    if($total === 0) {
        return;
    }

    $checksToRerun = "Reruning the following checks: \n";
    foreach ($failedCheckRuns as $failedCheckRun) {
        $url = $metadata["repoPrefix"] . "/check-runs/" . $failedCheckRun->id . "/rerequest";
        $response = doRequestGitHub($metadata["token"], $url, null, "POST");
        $status = $response->statusCode === 201 ? "🔄" : "❌";
        $checksToRerun .= "- [" . $failedCheckRun->name . "](" . $failedCheckRun->details_url . ") ([" . $failedCheckRun->app->name . " ](" . $failedCheckRun->app->html_url . " )) - " . $status . "\n";
    }

    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $checksToRerun), "POST");
}

function execute_rerunFailedWorkflows($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestUpdated = json_decode($pullRequestResponse->body);
    $commitSha1 = $pullRequestUpdated->head->sha;
    $failedWorkflowRunsResponse = doRequestGitHub($metadata["token"], $metadata["repoPrefix"] . "/actions/runs?head_sha=" . $commitSha1 . "&status=failure", null, "GET");
    $failedWorkflowRuns = json_decode($failedWorkflowRunsResponse->body);
    $total = $failedWorkflowRuns->total_count;

    $body = "Rerunning " . $total . " failed workflow" . ($total === 1 ? "" : "s") . " on the commit `" . $commitSha1 . "`! :repeat:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    if($total === 0) {
        return;
    }

    $actionsToRerun = "Reruning the following workflows: \n";
    foreach ($failedWorkflowRuns->workflow_runs as $failedWorkflowRun) {
        $url = $metadata["repoPrefix"] . "/actions/runs/" . $failedWorkflowRun->id . "/rerun-failed-jobs";
        $response = doRequestGitHub($metadata["token"], $url, null, "POST");
        $status = $response->statusCode === 201 ? "🔄" : "❌";
        $actionsToRerun .= "- [" . $failedWorkflowRun->name . "](" . $failedWorkflowRun->html_url . ") - " . $status . "\n";
    }

    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $actionsToRerun), "POST");
}

function execute_review($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "+1"), "POST");

    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequestUpdated = json_decode($pullRequestResponse->body);

    $commitsResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"] . "/commits?per_page=100", null, "GET");
    $commits = json_decode($commitsResponse->body);
    
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

    foreach ($commits as $commitItem) {
        $commit = new \stdClass();
        $commit->HookId = $comment->HookId;
        $commit->TargetId = $comment->TargetId;
        $commit->TargetType = $comment->TargetType;
        $commit->RepositoryOwner = $comment->RepositoryOwner;
        $commit->RepositoryName = $comment->RepositoryName;
        $commit->Ref = $pullRequestUpdated->head->ref;
        $commit->HeadCommitId = $commitItem->sha;
        $commit->HeadCommitTreeId = $commitItem->commit->tree->sha;
        $commit->HeadCommitMessage = $commitItem->commit->message;
        $commit->HeadCommitTimestamp = date("Y-m-d H:i:s", strtotime($commitItem->commit->author->date));
        $commit->HeadCommitAuthorName = $commitItem->commit->author->name;
        $commit->HeadCommitAuthorEmail = $commitItem->commit->author->email;
        $commit->HeadCommitCommitterName = $commitItem->commit->committer->name;
        $commit->HeadCommitCommiterEmail = $commitItem->commit->committer->email;
        $commit->InstallationId = $comment->InstallationId;

        upsertCommit($commit);
    }
    
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Review enabled! :eyes:"), "POST");
}

function execute_track($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    $body = array("body" => "Tracking this pull request! :repeat:");
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], $body, "POST");
    callWorkflow($config, $metadata, $comment, "track.yml");
}

function execute_updateSnapshot($config, $metadata, $comment)
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "eyes"), "POST");
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Updating test snapshots"), "POST");
    callWorkflow($config, $metadata, $comment, "update-test-snapshot.yml");
}

function callWorkflow($config, $metadata, $comment, $workflow)
{
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequest = json_decode($pullRequestResponse->body);

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
    doRequestGitHub($tokenBot, $url, $data, "POST");
}

function checkIfPullRequestIsOpen($metadata)
{
    $pullRequestResponse = doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET");
    $pullRequest = json_decode($pullRequestResponse->body);

    $metadata["headRef"] = $pullRequest->head->ref;
    $metadata["headSha"] = $pullRequest->head->sha;

    if ($pullRequest->state === "open") {
        return true;
    }
    return false;
}

function getAppVeyorProject($metadata, $comment)
{
    $project = findProjectByRepositorySlug($comment->RepositoryOwner . "/" . $comment->RepositoryName);

    if ($project == null) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Response is null"), "POST");
        return null;
    }

    if ($project->error) {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = $project->message;
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
        return null;
    }

    return $project;
}

function updateNextBuildNumber($metadata, $project, $nextBuildNumber)
{
    $data = array("nextBuildNumber" => $nextBuildNumber);
    $url = "projects/" . $project->accountName . "/" . $project->slug . "/settings/build-number";
    $updateResponse = requestAppVeyor($url, $data, true);

    if ($updateResponse->statusCode !== 204) {
        $commentBody = "AppVeyor update next build number failed: :x:\r\n\r\n```\r\n" . $updateResponse->body . "\r\n```\r\n";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
        return;
    }

    $commentBody = "AppVeyor next build number updated to " . $nextBuildNumber . "! :rocket:";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $commentBody), "POST");
}


function main()
{
    $comments = readTable("github_pull_requests_comments");
    foreach ($comments as $comment) {
        handleComment($comment);
        updateTable("github_pull_requests_comments", $comment->Sequence);
    }
}

$healthCheck = new HealthChecks($healthChecksIoComments, GUIDv4::random());
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
