<?php

namespace GuiBranco\GStracciniBot\Handlers;

/**
 * Handles push events shared by the HTTP webhook entry point
 * (Src/pushes.php) and the queue worker (Src/Workers/pushes.php).
 */
class PushesHandler implements IHandler
{
    public function handleItem($push)
    {
        global $logStream;

        echo "https://github.com/{$push->RepositoryOwner}/{$push->RepositoryName}/commit/{$push->HeadCommitId}:\n\n";

        $logStream?->info(
            "Processing push event on {$push->Ref}",
            ['repo' => "{$push->RepositoryOwner}/{$push->RepositoryName}", 'ref' => $push->Ref, 'commit' => $push->HeadCommitId],
            "pushes",
            $push->DeliveryIdText
        );

        $config = loadConfig();
        $token = generateInstallationToken($push->InstallationId, $push->RepositoryName);

        $commitQueryString =
            "commits/?owner=" . $push->RepositoryOwner .
            "&repo=" . $push->RepositoryName .
            "&ref=" . urlencode($push->Ref);

        $repoPrefix = "repos/" . $push->RepositoryOwner . "/" . $push->RepositoryName;
        $metadata = array(
            "token" => $token,
            "repoUrl" => $repoPrefix,
            "checkRunUrl" => $repoPrefix . "/check-runs",
            "dashboardUrl" => $config->dashboardUrl . $commitQueryString
        );

        $checkRunId = setCheckRunInProgress($metadata, $push->HeadCommitId, "commit");
        setCheckRunSucceeded($metadata, $checkRunId, "commit");
    }
}
