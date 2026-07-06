<?php

namespace GuiBranco\GStracciniBot\Handlers;

use GuiBranco\GStracciniBot\Library\LabelHelper;
use GuiBranco\GStracciniBot\Library\RepositoryManager;

/**
 * Handles repository events shared by the HTTP webhook entry point
 * (Src/repositories.php) and the queue worker (Src/Workers/repositories.php).
 */
class RepositoriesHandler implements IHandler
{
    public function handleItem($repository)
    {
        global $logStream, $gitHubUserToken;

        echo "https://github.com/{$repository->FullName}:\n\n";

        $logStream?->info(
            "Processing repository event for {$repository->FullName}",
            ['repo' => $repository->FullName, 'owner' => $repository->OwnerLogin],
            "repositories",
            $repository->DeliveryIdText
        );

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
        $this->createRepositoryLabels($metadata, $repositoryOptions);
    }

    private function createRepositoryLabels($metadata, $options)
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
}
