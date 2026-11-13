<?php

namespace GuiBranco\GStracciniBot\Handlers;

use GuiBranco\GStracciniBot\Library\LogContext;

/**
 * Handles push events shared by the HTTP webhook entry point
 * (Src/pushes.php) and the queue worker (Src/Workers/pushes.php).
 */
class PushesHandler implements IHandler
{
    public function handleItem($push)
    {
        global $logStream;


        $logStream?->info(
            'Processing push to {ref} of {repo}',
            (new LogContext(repo: "{$push->RepositoryOwner}/{$push->RepositoryName}", ref: $push->Ref, commit: $push->HeadCommitId))->toArray(),
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
        try {
            setCheckRunSucceeded($metadata, $checkRunId, "commit");
        } catch (\Throwable $e) {
            failCheckRunOnError($metadata, $checkRunId, "commit", $e);
            throw $e;
        }

        $commitSubject = strtok($push->HeadCommitMessage, "\n");

        recordRecentActivity(
            $push->RepositoryOwner,
            $push->RepositoryName,
            $push->InstallationId,
            "pushed_commits",
            $commitSubject,
            "https://github.com/{$push->RepositoryOwner}/{$push->RepositoryName}/commit/{$push->HeadCommitId}"
        );
    }
}
