<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($push)
{
    echo "https://github.com/{$push->RepositoryOwner}/{$push->RepositoryName}/commit/{$push->HeadCommitId}:\n\n";

    $config = loadConfig();
    $token = generateInstallationToken($push->InstallationId, $push->RepositoryName);

    $commitQueryString =
        "commits/?owner=" . $push->RepositoryOwner .
        "&repo=" . $push->RepositoryName .
        "&ref=" . urlencode($push->Ref);

    $repoPrefix = "repos/" . $push->RepositoryOwner . "/" . $push->RepositoryName;
    $metadata = array(
        "token" => $token,
        "repoUrl" => $repoPrefix,
        "checkRunUrl" => $repoPrefix . "/check-runs",
        "dashboardUrl" => $config->dashboardUrl . $commitQueryString
    );

    $checkRunId = setCheckRunInProgress($metadata, $push->HeadCommitId, "commit");
    setCheckRunSucceeded($metadata, $checkRunId, "commit");
}

function main(): void
{
    $config = loadConfig();
    ob_start();
    $table = "github_pushes";
    global $logger;
    $processor = new ProcessingManager($table, $logger);
    $processor->process('handleItem');
    $result = ob_get_clean();
    if ($config->debug->all === true || $config->debug->pushes === true) {
        echo $result;
    }
}

$healthCheck = new HealthChecks($healthChecksIoPushes, GUIDv4::random());
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
