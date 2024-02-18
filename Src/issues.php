<?php

require_once "vendor/autoload.php";
require_once "config/config.php";

function handleIssue($issue)
{
    global $gitHubUserToken;

    $token = generateInstallationToken($issue->InstallationId, $issue->RepositoryName);

    $metadata = array(
        "token" => $token,
        "repoUrl" => "repos/" . $issue->RepositoryOwner . "/" . $issue->RepositoryName,
        "collaboratorsUrl" => "repos/" . $issue->RepositoryOwner . "/" . $issue->RepositoryName . "/collaborators",
        "issuesUrl" => "repos/" . $issue->RepositoryOwner . "/" . $issue->RepositoryName . "/issues/" . $issue->Number,
    );

    $issueResponse = requestGitHub($metadata["token"], $metadata["issuesUrl"]);
    $issueUpdated = json_decode($issueResponse["body"]);

    if ($issueUpdated->assignee != null) {
        return;
    }
    $repositoryResponse = requestGitHub($metadata["token"], $metadata["repoUrl"]);
    $repository = json_decode($repositoryResponse["body"]);
    if (!$repository->private) {
        return;
    }
    $collaboratorsResponse = requestGitHub($metadata["token"], $metadata["collaboratorsUrl"]);
    $collaborators = json_decode($collaboratorsResponse["body"]);
    $collaboratorsLogins = array_column($collaborators, "login");
    $body = array("assignees" => $collaboratorsLogins);
    requestGitHub($metadata["token"], $metadata["assigneesUrl"], $body);
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
