<?php

require_once "config/config.php";

use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function installSignature($signature)
{
    global $gitHubUserToken, $gitHubWebhookEndpoint, $gitHubWebhookSignature;

    $request = array(
        "content_type" => "json",
        "insecure_ssl" => "0",
        "url" => $gitHubWebhookEndpoint,
        "secret" => $gitHubWebhookSignature
    );

    $repoPrefix = "repos/" . $signature->RepositoryOwner . "/" . $signature->RepositoryName;
    $url = "";
    if ($signature->TargetType == "repository") {
        $url = $repoPrefix . "/hooks/" . $signature->HookId . "/config";
    } elseif ($signature->TargetType == "organization") {
        $url = "repos/" . $signature->RepositoryOwner . "/hooks/" . $signature->HookId . "/config";
    }

    if (!empty($url)) {
        doRequestGitHub($gitHubUserToken, $url, $request, "POST");
    }
}

function main()
{
    $signatures = readTable("github_signature");
    foreach ($signatures as $signature) {
        installSignature($signature);
        updateTable("github_signature", $signature->Sequence);
    }
}

$healthCheck = new HealthChecks($healthChecksIoSignature, GUIDv4::random());
$healthCheck->start();
main();
$healthCheck->end();
