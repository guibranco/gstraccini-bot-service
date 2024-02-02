<?php

require "vendor/autoload.php";
require "config/config.php";

function handleIssue($issue)
{
    global $gitHubUserToken;

    $token = generateInstallationToken($issue->InstallationId, $issue->RepositoryName);

    $metadata = array(
        "token" => $token,
        "issuesUrl" => "repos/" . $issue->RepositoryOwner . "/" . $issue->RepositoryName . "/issues/" . $issue->Number,
    );

    $issueResponse = requestGitHub($metadata["token"], $metadata["issuesUrl"]);
    $issueUpdated = json_decode($issueResponse["body"]);

    if ($issueUpdated->state != "open") {
        return;
    }

    echo "Issue " . $issue->Number . " is open\n";
}

function main()
{
    $issues = readTable("github_issues");
    foreach ($issues as $issue) {
        handleIssue($issue);
        updateTable("github_issues", $issue->Sequence);
    }
}

sendHealthCheck($healthChecksIoIssues, "/start");
main();
sendHealthCheck($healthChecksIoIssues);
