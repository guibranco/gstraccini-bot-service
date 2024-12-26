<?php
namespace Src\Library;

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
            "Accept: application/vnd.api+json",
            "Authorization: Token token={$this->apiToken}"
        ];

        $response = $this->makeRequest('GET', $url, $headers);

        if ($response->statusCode >= 300) {
            $this->logger->log("Error retrieving CodeClimate repository ID", json_encode($response));
            throw new \Exception("Failed to retrieve repository ID from CodeClimate");
        }

        return $response->data[0]->id;
    }

    public function bypassPRCheck(string $repositoryId, string $pullRequestNumber): stdClass
    {
        $url = "{$this->baseUrl}repos/{$repositoryId}/pulls/{$pullRequestNumber}/approvals";
        $headers = [
            "Accept: application/vnd.api+json",
            "Authorization: Token token={$this->apiToken}"
        ];
        $data = http_build_query([
            "data[attributes][reason]" => "merge"
        ]);

        $response = $this->makeRequest('POST', $url, $headers, $data);

        if ($response->statusCode >= 300) {
            $this->logger->log("Error bypassing CodeClimate PR check", json_encode($response));
            throw new \Exception("Failed to bypass PR check in CodeClimate");
        }

        return $response;
    }

    private function makeRequest(string $method, string $url, array $headers, string $data = null): stdClass
    {
        // Implement HTTP request logic here (e.g., using cURL or Guzzle)
    }
}