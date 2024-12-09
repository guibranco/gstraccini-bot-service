<?php

namespace Src\Snyk;

class SnykRegistrar
{
    private $apiToken;
    private $httpClient;

    public function __construct($apiToken, $httpClient)
    {
        $this->apiToken = $apiToken;
        $this->httpClient = $httpClient;
    }

    public function registerRepository($repositoryDetails)
    {
        $url = "https://snyk.io/api/v1/org/YOUR_ORG_ID/projects";
        $headers = [
            'Authorization: token ' . $this->apiToken,
            'Content-Type: application/json'
        ];
        $response = $this->httpClient->post($url, $headers, json_encode($repositoryDetails));
        return $response;
    }

}
