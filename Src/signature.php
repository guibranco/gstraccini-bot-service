<?php

require_once "vendor/autoload.php";
require_once "config/config.php";

function installSignature($signature)
{
    global $gitHubUserToken, $gitHubWebhookEndpoint, $gitHubWebhookSignature;

    $request = array(
        "content_type" => "json",
        "insecure_ssl" => "0",
        "url" => $gitHubWebhookEndpoint,
        "secret" => $gitHubWebhookSignature
    );

    $url = "";
    if ($signature->TargetType == "repository") {
        $url = "repos/" . $signature->RepositoryOwner . "/" . $signature->RepositoryName . "/hooks/" . $signature->HookId . "/config";
    } else if ($signature->TargetType == "organization") {
        $url = "repos/" . $signature->RepositoryOwner . "/hooks/" . $signature->HookId . "/config";
    }

    if (!empty($url)) {
       requestGitHub($gitHubUserToken, $url, $request);
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

sendHealthCheck($healthChecksIoSignature, "/start");
main();
sendHealthCheck($healthChecksIoSignature);
