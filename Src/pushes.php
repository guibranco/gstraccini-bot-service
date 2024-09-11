<?php

require_once "config/config.php";

use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($push)
{
    $token = generateInstallationToken($push->InstallationId, $push->RepositoryName);

    $botDashboardUrl = "https://gstraccini.bot/dashboard";
    $commitQueryString =
        "?owner=" . $push->RepositoryOwner .
        "&repo=" . $push->RepositoryName .
        "&ref=" . urlencode($push->Ref);

    $repoPrefix = "repos/" . $push->RepositoryOwner . "/" . $push->RepositoryName;
    $metadata = array(
        "token" => $token,
        "repoUrl" => $repoPrefix,
        "checkRunUrl" => $repoPrefix . "/check-runs",
        "dashboardUrl" => $botDashboardUrl . $commitQueryString
    );

    $checkRunId = setCheckRunInProgress($metadata, $push->HeadCommitId, "commit");
    setCheckRunCompleted($metadata, $checkRunId, "commit");
}

function main()
{
    $config = loadConfig();
    ob_start();
    $table = "github_pushes";
    $items = readTable($table);
    foreach ($items as $item) {
        echo "Sequence: {$item->Sequence}\n";
        echo "Delivery ID: {$item->DeliveryIdText}\n";
        updateTable($table, $item->Sequence);
        handleItem($item);
        echo str_repeat("=-", 50) . "=\n";
    }
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
