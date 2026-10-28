<?php

namespace GuiBranco\GStracciniBot\Handlers;

use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\GStracciniBot\Library\LabelHelper;
use GuiBranco\GStracciniBot\Library\RepositoryManager;
use RuntimeException;

/**
 * Handles repository events shared by the HTTP webhook entry point
 * (Src/repositories.php) and the queue worker (Src/Workers/repositories.php).
 *
 * `github_repositories` is an event log, and not every row announces a
 * repository to set up: the installations handler and the scheduled syncs
 * append a `deleted`/`removed` row (ProcessingState NEW, like any other row)
 * when a repository is no longer accessible to its installation, and the
 * receiver flips the rows of a repository deleted on GitHub to `deleted`.
 * There is nothing to set up for those — the repository can't be reached
 * any more — so they are finalized without a GitHub call. Likewise a
 * repository whose installation no longer exists (token request 404) or
 * that the installation can no longer access (422) is recorded as removed,
 * as the syncs do, and finalized instead of failing again every hour.
 */
class RepositoriesHandler implements IHandler
{
    private CheckupRepository $repository;

    /**
     * @param CheckupRepository|null $repository `github_repositories` access, for recording a
     *                                           repository that turned out to be gone; built when omitted.
     */
    public function __construct(?CheckupRepository $repository = null)
    {
        $this->repository = $repository ?? new CheckupRepository();
    }

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

        if ($this->isRemoved($repository)) {
            $installationAction = $repository->InstallationAction ?? "none";
            echo "⛔ Repository is no longer accessible to installation #{$repository->InstallationId} " .
                "(LastAction: {$repository->LastAction}, InstallationAction: {$installationAction}) — nothing to set up\n";
            $logStream?->info(
                "Skipping removed repository {$repository->FullName}",
                [
                    'repo' => $repository->FullName,
                    'owner' => $repository->OwnerLogin,
                    'installationId' => (int) $repository->InstallationId,
                    'lastAction' => $repository->LastAction,
                    'installationAction' => $repository->InstallationAction ?? null,
                ],
                "repositories",
                $repository->DeliveryIdText
            );
            return;
        }

        $token = $this->createToken($repository);
        if ($token === null) {
            return;
        }

        $config = loadConfig();

        $repoQueryString =
            "repositories/?owner=" . $repository->OwnerLogin .
            "&repo=" . $repository->Name;

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

    /**
     * Whether the row records that the repository is gone rather than
     * announcing it: `deleted` is what the receiver writes for the `deleted`
     * event and what CheckupRepository::markRepositoryRemoved() appends
     * (together with InstallationAction `removed`).
     *
     * @param object $repository A row from the `github_repositories` table.
     *
     * @return bool True when the row says the repository is gone.
     */
    public function isRemoved(object $repository): bool
    {
        return ($repository->LastAction ?? null) === "deleted"
            || ($repository->InstallationAction ?? null) === "removed";
    }

    /**
     * Creates the installation access token for the repository, scoped by
     * repository id (not name, so a renamed or transferred repository still
     * matches). Returns null — after recording the repository as removed, the
     * way the syncs do — when GitHub answers that the installation no longer
     * exists (404) or that the repository isn't accessible to it any more
     * (422): both mean there is nothing to set up, not that something failed.
     *
     * @param object $repository A row from the `github_repositories` table.
     *
     * @return string|null The token, or null when the repository is gone.
     *
     * @throws RuntimeException When the token can't be created for any other reason
     *                          (suspended installation, bad credentials, GitHub error) —
     *                          the item then fails and is retried, as before.
     */
    private function createToken(object $repository): ?string
    {
        global $logStream, $version;

        $repositoryId = (int) $repository->Id;
        $installationId = (int) $repository->InstallationId;

        $response = requestInstallationAccessToken((string) $installationId, [$repositoryId]);
        $status = $response->getStatusCode();

        $reason = match ($status) {
            404 => "Installation #{$installationId} no longer exists",
            422 => "No longer accessible to installation #{$installationId}",
            default => null,
        };

        if ($reason !== null) {
            echo "⚠️ {$reason} (token request HTTP {$status}) — marking repository removed, nothing to set up\n";
            $logStream?->warning(
                "Repository {$repository->FullName} is gone: {$reason} — marking removed",
                [
                    'repo' => $repository->FullName,
                    'owner' => $repository->OwnerLogin,
                    'repositoryId' => $repositoryId,
                    'installationId' => $installationId,
                    'status' => $status,
                ],
                "repositories",
                $repository->DeliveryIdText
            );

            // Only the latest row says whether the repository is still registered as active;
            // a sync may already have appended the removed row in the meantime.
            if ($this->repository->existsRepository($repositoryId)) {
                $this->repository->markRepositoryRemoved(
                    $repositoryId,
                    $installationId,
                    "repositories_handler:" . ($version ?? "unknown")
                );
            }

            return null;
        }

        if (!$response->isSuccessStatusCode()) {
            throw new RuntimeException(
                "Could not create an access token for repository {$repository->FullName} (#{$repositoryId}, installation #{$installationId}): " .
                "HTTP status {$status}. Body: " . ($response->getBody() ?? $response->getMessage())
            );
        }

        return decodeGitHubResponse($response, "token");
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
