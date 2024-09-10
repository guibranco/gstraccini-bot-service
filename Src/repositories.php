<?php

require_once "config/config.php";

use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($repository)
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
        "labelsUrl" => $repoPrefix . "/labels",
        "userToken" => $gitHubUserToken,
        "botNameMarkdown" => "[" . $config->botName . "\[bot\]](https://github.com/apps/" . $config->botName . ")",
        "dashboardUrl" => $botDashboardUrl . $prQueryString
    );

    $repositoryOptions = getRepositoryOptions($metadata);
    $languages = getRepositoryLanguages($metadata);
    foreach ($languages as $language=>$lines) {
        echo "Language: {$language}: {$lines}\n";
    }
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

    if ($fileContentResponse === null) {
        return getDefaultOptions();
    }

    $fileContent = json_decode($fileContentResponse->body, true);
    return getDefaultOptions();
}

function getDefaultOptions()
{
    return array("labels" => array("style" => "icons", "categories" => "all"));
}

function createRepositoryLabels($metadata, $options)
{
    if (isset($options["labels"]) === false || $options["labels"] === null || $options["labels"] === "") {
        echo "Not creating labels\n";
        return;
    }

    $style = $options["labels"]["style"];
    $categories = $options["labels"]["categories"];

    $labelsToCreate = loadLabelsFromConfig($categories);
    if ($labelsToCreate === null || count($labelsToCreate) === 0) {
        echo "No labels to create\n";
        return;
    }

    $existingLabels = getRepositoryLabels($metadata);

    $labelsToUpdateObject = array();
    $labelsToCreate = array_filter($labelsToCreate, function ($label) use ($existingLabels, &$labelsToUpdateObject, $style) {
        $existingLabel = array_filter($existingLabels, function ($existingLabel) use ($label) {
            return $existingLabel["name"] === $label["text"] || $existingLabel["name"] === $label["textWithIcon"];
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

    echo "Creating labels {$totalLabelsToCreate} | Updating labels: {$totalLabelsToUpdate} | Style: {$style} | Categories: {$categories}\n";

    foreach ($labelsToCreateObject as $label) {
        $response = doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $label, "POST");
        if ($response->statusCode === 201) {
            echo "Label created: {$label["name"]}\n";
        } else {
            echo "Error creating label: {$label["name"]}\n";
        }
    }

    foreach ($labelsToUpdateObject as $oldName => $label) {
        $response = doRequestGitHub($metadata["token"], $metadata["labelsUrl"] . "/" . str_replace(" ", "%20", $oldName), $label, "PATCH");
        if ($response->statusCode === 200) {
            echo "Label updated: {$oldName} -> {$label["new_name"]}\n";
        } else {
            echo "Error updating label: {$oldName}\n";
        }
    }
}

function loadLabelsFromConfig($categories)
{
    $fileNameLabels = "config/labels.json";
    $labels = array();

    if (file_exists($fileNameLabels)) {
        $rawLabels = file_get_contents($fileNameLabels);
        $labels = json_decode($rawLabels, true);
    }

    unset($labels["language"]);

    $keys = array_keys($labels);

    if (is_array($categories)) {
        $keys = array_intersect($keys, $categories);
    } elseif ($categories !== "all") {
        $keys = array();
        if (in_array($categories, $keys)) {
            $keys = array($categories);
        }
    }

    if (count($keys) === 0) {
        return null;
    }

    $finalLabels = array();
    foreach ($keys as $key) {
        $finalLabels = array_merge($finalLabels, $labels[$key]);
    }

    return $finalLabels;
}

function getRepositoryLabels($metadata)
{
    $labelsResponse = doRequestGitHub($metadata["token"], $metadata["labelsUrl"], null, "GET");
    if ($labelsResponse->statusCode !== 200) {
        return array();
    }

    return json_decode($labelsResponse->body, true);
}

function getRepositoryLanguages($metadata)
{
    $languagesResponse = doRequestGitHub($metadata["token"], $metadata["repoUrl"] . "/languages", null, "GET");
    if ($languagesResponse->statusCode !== 200) {
        return array();
    }

    return json_decode($languagesResponse->body, true);
}

function main()
{
    $config = loadConfig();
    ob_start();
    $table = "github_repositories";
    $items = readTable($table);
    foreach ($items as $item) {
        echo "Sequence: {$item->Sequence}\n";
        echo "Delivery ID: {$item->DeliveryIdText}\n";
        updateTable($table, $item->Sequence);
        handleItem($item);
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
