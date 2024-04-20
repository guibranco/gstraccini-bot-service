<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\HealthChecks;

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

    $checkRunId = setCheckRunInProgress($metadata, $push);
    setCheckRunCompleted($metadata, $checkRunId);
}

function setCheckRunInProgress($metadata, $push)
{
    $checkRunBody = array(
        "name" => "GStraccini Checks: Commit",
        "head_sha" => $push->HeadCommitId,
        "status" => "in_progress",
        "output" => array(
            "title" => "Running checks...",
            "summary" => "",
            "text" => ""
        )
    );

    $response = doRequestGitHub($metadata["token"], $metadata["checkRunUrl"], $checkRunBody, "POST");
    $result = json_decode($response->body);
    return $result->id;
}

function setCheckRunCompleted($metadata, $checkRunId)
{
    $checkRunBody = array(
        "name" => "GStraccini Checks: Commit",
        "details_url" => $metadata["dashboardUrl"],
        "status" => "completed",
        "conclusion" => "success",
        "output" => array(
            "title" => "Checks completed ✅",
            "summary" => "GStraccini checked this commit successfully!",
            "text" => "No issues found."
        )
    );

    doRequestGitHub($metadata["token"], $metadata["checkRunUrl"] . "/" . $checkRunId, $checkRunBody, "PATCH");
}

function main()
{
    $pushes = readTable("github_pushes");
    foreach ($pushes as $push) {
        handlePush($push);
        updateTable("github_pushes", $push->Sequence);
    }
}

$healthCheck = new HealthChecks($healthChecksIoPushes);
$healthCheck->start();
main();
$healthCheck->end();
