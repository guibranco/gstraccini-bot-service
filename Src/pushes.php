<?php

require_once "config/config.php";

use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handlePush($push)
{
    $token = generateInstallationToken($push->InstallationId, $push->RepositoryName);

    $botDashboardUrl = "https://bot.straccini.com/dashboard";
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
    $pushes = readTable("github_pushes");
    foreach ($pushes as $push) {
        handlePush($push);
        updateTable("github_pushes", $push->Sequence);
    }
}

$healthCheck = new HealthChecks($healthChecksIoPushes, GUIDv4::random());
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
