<?php

require_once "config/config.php";

use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

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
        removeAwaitingTriageLabel($issueUpdated, $metadata);
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

    addLabels($issueUpdated, $collaboratorsLogins, $metadata);

    if(in_array($issueUpdated->user->login, $collaboratorsLogins)) {
        removeAwaitingTriageLabel($issueUpdated, $metadata);
    }    
}

function addLabels($issueUpdated, $collaboratorsLogins, $metadata)
{
    $labels = [];
    if (!in_array($issueUpdated->user->login, $collaboratorsLogins)) {
        $labels[] = "🚦awaiting triage";
    }

    if ($issueUpdated->user->type === "Bot") {
        $labels[] = "🤖 bot";
    }

    if (count($labels) > 0) {
        $body = array("labels" => $labels);
        doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/labels", $body, "POST");
    }
}

function removeAwaitingTriageLabel($issueUpdated,  $metadata)
{
    $awaitingTriageLabel = "🚦awaiting triage";
    $labels = array_column($issueUpdated->labels, "name");
    if (in_array($awaitingTriageLabel, $labels)) {
        $url = "{$metadata["issuesUrl"]}/{{$issueUpdated->number}/labels/{$awaitingTriageLabel}";
        doRequestGitHub($metadata["token"], $url, null, "DELETE");
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
$healthCheck->setHeaders(["User-Agent: " . constant("USER_AGENT"), "Content-Type: application/json; charset=utf-8"]);
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
