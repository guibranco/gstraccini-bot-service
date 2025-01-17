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

$healthCheck = new HealthChecks($healthChecksIoPushes, GUIDv4::random());
$processor = new ProcessingManager("pushes", $healthCheck, $logger);
$processor->initialize("handleItem", 55);
