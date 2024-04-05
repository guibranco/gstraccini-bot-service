<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\lib\HealthChecks;

function handleIssue($issue)
{
    $token = generateInstallationToken($issue->InstallationId, $issue->RepositoryName);

    $repoPrefix = "repos/" . $issue->RepositoryOwner . "/" . $issue->RepositoryName;
    $metadata = array(
        "token" => $token,
        "repoUrl" => $repoPrefix,
        "assigneesUrl" => $repoPrefix . "/issues/" . $issue->Number . "/assignees",
        "collaboratorsUrl" => $repoPrefix . "/collaborators",
        "issuesUrl" => $repoPrefix . "/issues/" . $issue->Number,
    );

    $issueResponse = doRequestGitHub($metadata["token"], $metadata["issuesUrl"], null, "GET");
    $issueUpdated = json_decode($issueResponse->body);

    if ($issueUpdated->assignee != null) {
        return;
    }
    $repositoryResponse = doRequestGitHub($metadata["token"], $metadata["repoUrl"], null, "GET");
    $repository = json_decode($repositoryResponse->body);
    if (!$repository->private) {
        return;
    }
    $collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
    $collaborators = json_decode($collaboratorsResponse->body, true);
    $collaboratorsLogins = array_column($collaborators, "login");
    $body = array("assignees" => $collaboratorsLogins);
    doRequestGitHub($metadata["token"], $metadata["assigneesUrl"], $body, "POST");
}

function main()
{
    $issues = readTable("github_issues");
    foreach ($issues as $issue) {
        handleIssue($issue);
        updateTable("github_issues", $issue->Sequence);
    }
}

$healthCheck = new HealthChecks($healthChecksIoIssues);
$healthCheck->start();
main();
$healthCheck->end();
