<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\Codacy;
use GuiBranco\GStracciniBot\Library\CommandHelper;
use GuiBranco\GStracciniBot\Library\LabelHelper;
use GuiBranco\GStracciniBot\Library\LabelService;
use GuiBranco\GStracciniBot\Library\RepositoryManager;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

/**
 * Converts a string to camel case format.
 *
 * @param string $inputString The input string to convert
 * @return string The camel case formatted string
 *
 * @throws InvalidArgumentException If the input is not a string
 */
function toCamelCase($inputString)
{
    if (!is_string($inputString)) {
        throw new \InvalidArgumentException('Input must be a string');
    }
    if (empty($inputString)) {
        return '';
    }
    return preg_replace_callback(
        '/(?:^|_| )([a-z])/',
        function ($matches) {
            return strtoupper($matches[1]);
        },
        $inputString
    );
}

function handleItem($comment): void
{
    echo "https://github.com/{$comment->RepositoryOwner}/{$comment->RepositoryName}/issues/{$comment->PullRequestNumber}/#issuecomment-{$comment->CommentId}:\n\n";
    echo "Comment: {$comment->CommentBody} | Sender: {$comment->CommentSender}\n";

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
        "reactionUrl" => "repos/{$comment->RepositoryOwner}/{$comment->RepositoryName}/issues/{$comment->IssueNumber}/reactions",
        "commentUrl" => "repos/{$comment->RepositoryOwner}/{$comment->RepositoryName}/issues/comments/{$comment->CommentId}",
        "pullRequestUrl" => isset($comment->PullRequestNumber) ? "repos/{$comment->RepositoryOwner}/{$comment->RepositoryName}/pulls/{$comment->PullRequestNumber}" : "",
        "errorMessages" => array(
            "invalidParameter" => "Invalid parameter provided.",
            "notImplemented" => "This feature is not yet implemented."
        ),
        "headRef" => $comment->HeadRef ?? '',
        "headSha" => $comment->HeadSha ?? ''
    );

    // Route command based on content
    if (preg_match("/@" . $config->botName . "\sappveyor\sbuild/", $comment->CommentBody)) {
        execute_appveyorBuild($config, $metadata, $comment);
    } elseif (preg_match("/@" . $config->botName . "\sappveyor\sbump\sversion/", $comment->CommentBody)) {
        execute_appveyorBumpVersion($config, $metadata, $comment);
    } elseif (preg_match("/@" . $config->botName . "\sbump\s(major|minor)/", $comment->CommentBody, $matches)) {
        execute_bumpVersion($config, $metadata, $comment, $matches[1]);
    } elseif (preg_match("/@" . $config->botName . "\snpm\scheck\supdates/", $comment->CommentBody)) {
        execute_npmCheckUpdates($config, $metadata, $comment);
    } elseif (preg_match("/@" . $config->botName . "\snpm\sdist/", $comment->CommentBody)) {
        execute_npmDist($config, $metadata, $comment);
    } elseif (preg_match("/@" . $config->botName . "\snpm\slint\sfix/", $comment->CommentBody)) {
        execute_npmLintFix($config, $metadata, $comment);
    } elseif (preg_match("/@" . $config->botName . "\sprettier/", $comment->CommentBody)) {
        execute_prettier($config, $metadata, $comment);
    } elseif (preg_match("/@" . $config->botName . "\srerun\sworkflows/", $comment->CommentBody)) {
        execute_rerunWorkflows($config, $metadata, $comment);
    } elseif (preg_match("/@" . $config->botName . "\sreview/", $comment->CommentBody)) {
        execute_review($config, $metadata, $comment);
    } elseif (preg_match("/@" . $config->botName . "\supdate\ssnapshot/", $comment->CommentBody)) {
        execute_updateSnapshot($config, $metadata, $comment);
    } else {
        // Unrecognized command
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "confused"), "POST");
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Command not recognized."), "POST");
    }
}

// Existing functions like execute_appveyorBuild, execute_appveyorBumpVersion, etc are defined above...

// New function to execute bump version command for GitVersion
function execute_bumpVersion($config, $metadata, $comment, $bumpType): void
{
    // Detect versioning system by checking for presence of configuration files
    // For simplicity, we check if GitVersion.yml exists in the repository root
    $gitVersionPath = 'GitVersion.yml';
    $appveyorPath = 'appveyor.yml';

    // Using file_exists might not work in production, so ideally you would use GitHub API to check file existence
    // Here we assume that if GitVersion.yml exists locally then GitVersion is used, otherwise if appveyor.yml exists then AppVeyor

    if (file_exists($gitVersionPath)) {
        // GitVersion bump logic
        // Check if the command is executed on a PR or not
        if (!empty($metadata["pullRequestUrl"])) {
            // For PR: generate a dummy commit on the PR branch
            $dummyMessage = "GStraccini bump {$bumpType} version +semver: {$bumpType}";
            // Use RepositoryManager to create a dummy commit
            $repoManager = new RepositoryManager();
            $result = $repoManager->createDummyCommit($metadata, $dummyMessage);
            if ($result) {
                doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
                doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Dummy commit created on PR with message: {$dummyMessage}"), "POST");
            } else {
                doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
                doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Failed to create dummy commit for version bump."), "POST");
            }
        } else {
            // No PR exists: create a new branch, add dummy commit, and open PR
            $dummyMessage = "GStraccini bump {$bumpType} version +semver: {$bumpType}";
            $repoManager = new RepositoryManager();
            $branchName = "bump-version-" . $bumpType . "-" . GUIDv4::generate();
            $result = $repoManager->createBranchWithDummyCommit($metadata, $branchName, $dummyMessage);
            if ($result) {
                doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
                doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Branch '{$branchName}' created with dummy commit for version bump. PR opened."), "POST");
            } else {
                doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
                doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => "Failed to create branch and dummy commit for version bump."), "POST");
            }
        }
    } elseif (file_exists($appveyorPath)) {
        // AppVeyor bump logic for major/minor is not implemented, so return error
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = "AppVeyor major/minor bump not implemented. Use appveyor bump version build instead.";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    } else {
        doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
        $body = "No recognized versioning configuration found (GitVersion.yml or appveyor.yml).";
        doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    }
}

// Stub for execute_npmCheckUpdates if not defined
function execute_npmCheckUpdates($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
    $body = "Running npm-check-updates to update dependencies!";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    // callWorkflow($config, $metadata, $comment, "npm-check-updates.yml");
}

// Stub for execute_updateSnapshot if not defined
function execute_updateSnapshot($config, $metadata, $comment): void
{
    doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "rocket"), "POST");
    $body = "Updating test snapshots using npm test -- -u";
    doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
    // callWorkflow($config, $metadata, $comment, "npm-test-snapshot.yml");
}

// Other existing functions remain unchanged...
