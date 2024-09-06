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
    $config = loadConfig();
    ob_start();
    $signatures = readTable("github_signature");
    foreach ($signatures as $signature) {
        installSignature($signature);
        updateTable("github_signature", $signature->Sequence);
    }
    $result = ob_get_clean();
    if ($config->debug->all === true || $config->debug->signature === true) {
        echo $result;
    }
}

$healthCheck = new HealthChecks($healthChecksIoSignature, GUIDv4::random());
$healthCheck->setHeaders([constant("USER_AGENT"), "Content-Type: application/json; charset=utf-8"]);
$healthCheck->start();
main();
$healthCheck->end();
