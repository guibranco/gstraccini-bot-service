<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($signature)
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

function main(): void
{
    $config = loadConfig();
    ob_start();
    $table = "github_signature";
    global $logger;
    $processor = new ProcessingManager($table, $logger);
    $processor->process('handleItem');
    $result = ob_get_clean();
    if ($config->debug->all === true || $config->debug->signature === true) {
        echo $result;
    }
}

$healthCheck = new HealthChecks($healthChecksIoSignature, GUIDv4::random());
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
