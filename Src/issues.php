<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($issue)
{
    echo "https://github.com/{$issue->RepositoryOwner}/{$issue->RepositoryName}/issues/{$issue->Number}:\n\n";

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

    if ($issueUpdated->state === "closed") {
        removeLabels($issueUpdated, $metadata, true);
        return;
    }

    if ($issueUpdated->assignee != null) {
        removeLabels($issueUpdated, $metadata);
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
        removeLabels($issueUpdated, $metadata);
        return;
    }

    addLabels($issueUpdated, $collaboratorsLogins, $metadata);

    if(in_array($issueUpdated->user->login, $collaboratorsLogins)) {
        removeLabels($issueUpdated, $metadata);
    }
}

function addLabels($issueUpdated, $collaboratorsLogins, $metadata)
{
    $labels = [];
    if (!in_array($issueUpdated->user->login, $collaboratorsLogins)) {
        $labels[] = "🚦 awaiting triage";
    }

    if ($issueUpdated->user->type === "Bot") {
        $labels[] = "🤖 bot";
    }

    if (count($labels) > 0) {
        $body = array("labels" => $labels);
        doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/labels", $body, "POST");
    }
}

function removeLabels($issueUpdated, $metadata, $includeWip = false)
{
    $labelsLookup = [
        "🚦 awaiting triage",
        "⏳ awaiting response"
    ];
    if ($includeWip === true) {
        $labelsLookup[] = "🛠 WIP";
    }

    $labels = array_column($issueUpdated->labels, "name");
    $intersect = array_intersect($labelsLookup, $labels);

    foreach ($intersect as $label) {
        $label = str_replace(" ", "%20", $label);
        $url = $metadata["issueUrl"] . "/labels/{$label}";
        doRequestGitHub($metadata["token"], $url, null, "DELETE");
    }
}


function main(): void
{
    $config = loadConfig();
    ob_start();
    $table = "github_issues";
    global $logger;
    $processor = new ProcessingManager($table, $logger);
    $processor->process('handleItem');
    $result = ob_get_clean();
    if ($config->debug->all === true || $config->debug->issues === true) {
        echo $result;
    }
}

$healthCheck = new HealthChecks($healthChecksIoIssues, GUIDv4::random());
$healthCheck->setHeaders([constant("USER_AGENT"), "Content-Type: application/json; charset=utf-8"]);
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
