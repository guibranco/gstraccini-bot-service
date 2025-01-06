<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\Request;
use GuiBranco\Pancake\Response;

/**
 * Class CodeClimate
 *
 * Handles interactions with the CodeClimate API, including retrieving repository IDs
 * and bypassing PR checks when necessary.
 */
class CodeClimate
{
    private $apiToken;
    private $logger;
    private $baseUrl = "https://api.codeclimate.com/v1/";

    public function __construct($apiToken, $logger)
    {
        $this->apiToken = $apiToken;
        $this->logger = $logger;
    }

    public function getRepositoryId(string $githubSlug): string
    {
        $url = "{$this->baseUrl}repos?github_slug={$githubSlug}";
        $headers = [
            constant("USER_AGENT"),
            "Accept: application/json",
            "Accept: application/vnd.api+json",
            "Authorization: Token token={$this->apiToken}",
        $request =  new Request();
        $response = $request->get($url, $headers);

        if ($response->getStatusCode() >= 300) {
            $this->logger->log("Error retrieving CodeClimate repository ID", json_encode($response));
            throw new \Exception(
                "Failed to retrieve repository ID from CodeClimate"
        }

        $body = json_decode($response->getBody());
        return $body->data[0]->id;
    }

    public function bypassPRCheck(string $repositoryId, string $pullRequestNumber): Response
    {
        $url = "{$this->baseUrl}repos/{$repositoryId}/pulls/{$pullRequestNumber}/approvals";
        $headers = [constant("USER_AGENT"), "Accept: application/json", "Accept: application/vnd.api+json", "Authorization: Token token={$this->apiToken}"];
        $data = json_encode(["data[attributes][reason]" => "merge"]);
        $request =  new Request();
        $response = $request->post($url, $headers, $data);

        if ($response->getStatusCode() >= 300) {
            $this->logger->log("Error bypassing CodeClimate PR check", json_encode($response));
            throw new \Exception("Failed to bypass PR check in CodeClimate");
        }

        return $response;
    }
}
