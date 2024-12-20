<?php

require_once "config/config.php";

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

    $labelService = new LabelService();
    $labelsToCreate = $labelService->loadFromConfig($categories);
    if ($labelsToCreate === null || count($labelsToCreate) === 0) {
        echo "⛔ No labels to create\n";
        return;
    }

    $repositoryManager = new RepositoryManager();
    $existingLabels = $repositoryManager->getLabels($metadata["userToken"], $metadata["repositoryOwner"], $metadata["repositoryName"]);

    $labelsToUpdateObject = array();
    $labelsToCreate = array_filter($labelsToCreate, function ($label) use ($existingLabels, &$labelsToUpdateObject, $style) {
        $existingLabel = array_filter($existingLabels, function ($existingLabel) use ($label) {
            return  strtolower($existingLabel["name"]) === strtolower($label["text"]) ||
                    strtolower($existingLabel["name"]) === strtolower($label["textWithIcon"]);
        });

        $total = count($existingLabel);

        if ($total > 0) {
            $existingLabel = array_values($existingLabel);
            $labelToUpdate = [];
            $labelToUpdate["color"] = substr($label["color"], 1);
            $labelToUpdate["description"] = $label["description"];
            $labelToUpdate["new_name"] = $style === "icons" ? $label["textWithIcon"] : $label["text"];
            $labelsToUpdateObject[$existingLabel[0]["name"]] = $labelToUpdate;
        }

        return $total === 0;
    });

    $labelsToCreateObject = array_map(function ($label) use ($style) {
        $newLabel = [];
        $newLabel["color"] = substr($label["color"], 1);
        $newLabel["description"] = $label["description"];
        $newLabel["name"] = $style === "icons" ? $label["textWithIcon"] : $label["text"];
        return $newLabel;
    }, $labelsToCreate);

    $totalLabelsToCreate = count($labelsToCreateObject);
    $totalLabelsToUpdate = count($labelsToUpdateObject);

    echo "🏃‍♂️ Creating labels {$totalLabelsToCreate} | Updating labels: {$totalLabelsToUpdate} | Style: {$style} | Categories: ".join(",", $categories)."\n";

    $labelService->processLabels($labelsToCreateObject, $labelsToUpdateObject, $metadata["token"], $metadata["labelsUrl"]);
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
