<?php

require_once "vendor/autoload.php";
require_once "config.php";
require_once "lib/functions.php";
require_once "lib/github.php";

function handlePullRequest($pullRequest)
{
    $config = loadConfig();
    
    $token = generateInstallationToken($pullRequest->InstallationId, $pullRequest->RepositoryName);
    $url = "repos/" . $pullRequest->RepositoryOwner . "/" . $pullRequest->RepositoryName . "/pulls/" . $pullRequest->PullRequestNumber . "/reviews";
    $body = array(
        "event" => "APPROVE",
        "body" => "Automatically approved by " . $config->botName . "[bot]"
    );
    print_r(requestGitHub($token, $url, $body));
}

function main()
{
    $pullRequests = readTable("github_pull_requests");
    foreach ($pullRequests as $pullRequest) {
        handlePullRequest($pullRequest);
        updateTable("github_pull_requests", $pullRequest->Sequence);
    }
}

sendHealthCheck("/start");
main();
sendHealthCheck();
