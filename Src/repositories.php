<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\LabelHelper;
use GuiBranco\GStracciniBot\Library\LabelService;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\GStracciniBot\Library\RepositoryManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($repository)
{
    echo "https://github.com/{$repository->FullName}:\n\n";

    global $gitHubUserToken;
    $config = loadConfig();

    $repoQueryString =
        "repositories/?owner=" . $repository->OwnerLogin .
        "&repo=" . $repository->Name;

    $token = generateInstallationToken($repository->InstallationId, $repository->Name);
    $repoPrefix = "repos/" . $repository->FullName;
    list($repositoryOwner, $repositoryName) = explode("/", $repository->FullName);
    $metadata = [
        "token" => $token,
        "repoUrl" => $repoPrefix,
        "labelsUrl" => $repoPrefix . "/labels",
        "userToken" => $gitHubUserToken,
        "botNameMarkdown" => "[" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")",
        "dashboardUrl" => $config->dashboardUrl . $repoQueryString,
        "repositoryOwner" => $repositoryOwner,
        "repositoryName" => $repositoryName
    ];

    $repositoryManager = new RepositoryManager();
    $repositoryOptions = $repositoryManager->getBotOptions($metadata["token"], $metadata["repositoryOwner"], $metadata["repositoryName"]);
    $languages = $repositoryManager->getLanguages($metadata["token"], $metadata["repositoryOwner"], $metadata["repositoryName"]);
    foreach ($languages as $language => $bytes) {
        echo "🔤 Language: {$language}: {$bytes} bytes\n";
    }
    echo "\n";
    createRepositoryLabels($metadata, $repositoryOptions);
}

function createRepositoryLabels($metadata, $options)
{
    if (isset($options["labels"]) === false || $options["labels"] === null || $options["labels"] === "") {
        echo "⛔ Not creating labels\n";
        return;
    }

    $style = $options["labels"]["style"] ?? "icons";
    $categories = $options["labels"]["categories"] ?? ["all"];

    $labelHelper = new LabelHelper();
    $labelHelper->createLabels($metadata, $style, $categories);
}

function main(): void
{
    $config = loadConfig();
    ob_start();
    $table = "github_repositories";
    global $logger;
    $processor = new ProcessingManager($table, $logger);
    $processor->process('handleItem');
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
