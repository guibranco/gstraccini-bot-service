<?php

require_once "config/config.php";

use GuiBranco\Pancake\GUIDv4;
use GuiBranco\GStracciniBot\Library\HealthChecks;

function handleIssue($issue)
{
    $token = generateInstallationToken($issue->InstallationId, $issue->RepositoryName);

    $repoPrefix = "repos/" . $issue->RepositoryOwner . "/" . $issue->RepositoryName;
    $metadata = array(
        "token" => $token,
        "repoUrl" => $repoPrefix,
        "assigneesUrl" => $repoPrefix . "/issues/" . $issue->Number . "/assignees",
        "collaboratorsUrl" => $repoPrefix . "/collaborators",
        "issueUrl" => $repoPrefix . "/issues/" . $issue->Number,
    );

    $issueResponse = doRequestGitHub($metadata["token"], $metadata["issueUrl"], null, "GET");
    $issueUpdated = json_decode($issueResponse->body);

    if ($issueUpdated->assignee != null) {
        return;
    }
    $repositoryResponse = doRequestGitHub($metadata["token"], $metadata["repoUrl"], null, "GET");
    $repository = json_decode($repositoryResponse->body);

    $collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
    $collaborators = json_decode($collaboratorsResponse->body, true);
    $collaboratorsLogins = array_column($collaborators, "login");

    if ($repository->private) {
        $body = array("assignees" => $collaboratorsLogins);
        doRequestGitHub($metadata["token"], $metadata["assigneesUrl"], $body, "POST");
        return;
    }

    if (!in_array($issueUpdated->user->login, $collaboratorsLogins)) {
        $body = array("labels" => ["awaiting-triage"]);
        doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/labels", $body, "POST");
    }
}

function main()
{
    $issues = readTable("github_issues");
    foreach ($issues as $issue) {
        handleIssue($issue);
        updateTable("github_issues", $issue->Sequence);
    }
}

$healthCheck = new HealthChecks($healthChecksIoIssues, GUIDv4::random());
$healthCheck->start();
$time = time();
while (true) {
    main();
    $limit = $time + 60;
    if ($limit < time()) {
        break;
    }
}
$healthCheck->end();
