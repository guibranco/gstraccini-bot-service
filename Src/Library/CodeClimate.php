<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\Logger;
use GuiBranco\Pancake\Request;
use GuiBranco\Pancake\Response;
use Exception;

/**
 * Class CodeClimate
 *
 * @category CodeAnalysis
 * Handles interactions with the CodeClimate API, including retrieving repository IDs
 * @license MIT
 * @package GuiBranco\GStracciniBot\Library
 * and bypassing PR checks when necessary.
 */
class CodeClimate
{
    private $apiToken;
    private $headers;
    private $logger;
    private $baseUrl = "https://api.codeclimate.com/v1/";

    /**
     * Retrieves the repository ID from CodeClimate using the GitHub repository slug.
     * @param string $apiToken The API token for CodeClimate API access.
     * @param Logger $logger The logger instance.
     */
    public function __construct(string $apiToken, Logger $logger)
    {
        $this->apiToken = $apiToken;
        $this->headers = [
            constant("USER_AGENT"),
            "Accept: application/json",
            "Accept: application/vnd.api+json",
            "Authorization: Token token={$this->apiToken}"
        ];
        $this->logger = $logger;
    }

    /**
     * Retrieves the repository ID from CodeClimate using the provided GitHub slug.
     *
     * @param string $githubSlug The GitHub repository slug (e.g., "username/repository").
     * @return string The repository ID from CodeClimate.
     */
    public function getRepositoryId(string $githubSlug): string
    {
        $url = "{$this->baseUrl}repos?github_slug={$githubSlug}";
        $request = new Request();
        $response = $request->get($url, $this->headers);

        if ($response->getStatusCode() >= 300) {
            $this->logger->log("Error retrieving CodeClimate repository ID", json_encode($response));
            throw new \Exception("Failed to retrieve repository ID from CodeClimate");
        }

        $body = json_decode($response->getBody());
        return $body->data[0]->id;
    }

    /**
     * Bypasses the CodeClimate PR check for a specific pull request.
     *
     * @param string $repositoryId      The ID of the repository.
     * @param string $pullRequestNumber The number of the pull request.
     *
     * @return Response Returns the response from the CodeClimate API.
     *
     * @throws \Exception If bypassing the PR check fails.
     */
    public function bypassPRCheck(string $repositoryId, string $pullRequestNumber): Response
    {
        $url = "{$this->baseUrl}repos/{$repositoryId}/pulls/{$pullRequestNumber}/approvals";
        $data = json_encode(["data[attributes][reason]" => "merge"]);
        $request = new Request();
        $response = $request->post($url, $this->headers, $data);

        if ($response->getStatusCode() >= 300) {
            $this->logger->log("Error bypassing CodeClimate PR check", json_encode($response));
            throw new \Exception("Failed to bypass PR check in CodeClimate");
        }

        return $response;
    }
}
