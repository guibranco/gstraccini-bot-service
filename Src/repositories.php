<?php

require_once "config/config.php";

use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleRepository($repository)
{
    echo "https://github.com/{$repository->FullName}:\n\n";

    global $gitHubUserToken;
    $config = loadConfig();

    $botDashboardUrl = "https://gstraccini.bot/dashboard";
    $prQueryString =
          "?owner=" . $repository->OwnerLogin .
          "&repo=" . $repository->Name;

    $token = generateInstallationToken($repository->InstallationId, $repository->Name);
    $repoPrefix = "repos/" . $repository->FullName;
    $metadata = array(
      "token" => $token,
      "repoUrl" => $repoPrefix,
      "userToken" => $gitHubUserToken,
      "botNameMarkdown" => "[" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")",
      "dashboardUrl" => $botDashboardUrl . $prQueryString
    );

    $repositoryOptions = getRepositoryOptions($metadata);
    createRepositoryLabels($metadata, $repositoryOptions);
}

function getRepositoryOptions($metadata)
{
    $paths = array("/", "/.github/");
    $fileContentResponse = null;
    foreach ($paths as $path) {
        $fileContentResponse = doRequestGitHub($metadata["token"], $metadata["repoUrl"] . "/contents" . $path . ".gstraccini.toml", null, "GET");
        if ($fileContentResponse->statusCode === 200) {
            break;
        }
    }

    if($fileContentResponse === null) {
        return getDefaultOptions();
    }

    $fileContent = json_decode($fileContentResponse->body, true);
    return getDefaultOptions();
}

function getDefaultOptions()
{
    return array("labels" => array("style" => "icons", "type" => "all"));
}

function createRepositoryLabels($metadata, $options)
{
    if(!isset($options["labels"]) || $options["labels"] === null || $options["labels"] === "") {
        echo "Not creating labels\n";
        return;
    }

    echo "Creating labels | Style: {$options["labels"]["style"]} | Type: {$options["labels"]["type"]}\n";
}

function main()
{
    $config = loadConfig();
    ob_start();
    $repositories = readTable("github_repositories");
    foreach ($repositories as $repository) {
        echo "Sequence: {$repository->Sequence}\n";
        echo "Delivery ID: {$repository->DeliveryIdText}\n";
        handleRepository($repository);
        updateTable("github_repositories", $repository->Sequence);
        echo str_repeat("=-", 50) . "=\n";
    }
    $result = ob_get_clean();
    if ($config->debug->all === true || $config->debug->repositories === true) {
        echo $result;
    }
}

$healthCheck = new HealthChecks($healthChecksIoRepositories, GUIDv4::random());
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
