<?php

namespace GuiBranco\GStracciniBot\Handlers;

/**
 * Handles webhook signature rotation events shared by the HTTP webhook entry point
 * (Src/signature.php) and the queue worker (Src/Workers/signature.php).
 */
class SignatureHandler implements IHandler
{
    public function handleItem($signature)
    {
        global $gitHubUserToken, $gitHubWebhookEndpoint, $gitHubWebhookSignature, $logStream;

        $logStream?->info(
            "Processing webhook signature update",
            ['repo' => "{$signature->RepositoryOwner}/{$signature->RepositoryName}", 'hook_id' => $signature->HookId, 'target_type' => $signature->TargetType],
            "signature",
            $signature->DeliveryIdText
        );

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
}
