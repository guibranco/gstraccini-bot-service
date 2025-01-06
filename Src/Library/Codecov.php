<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\Request;

class Codecov
{
    private $apiBaseUrl = 'https://api.codecov.io/v2';
    private $headers;
    private $request;

    public function __construct($token)
    {
        $this->headers =  [constant("USER_AGENT"), "Accept: application/json", "Content-Type: application/json", "Authorization: Bearer {$token}"];
        $this->request = new Request();
    }

    private function makeRequest($endpoint)
    {
        $url = $this->apiBaseUrl . $endpoint;
        $response = $this->request->get($url, $this->headers);

        return json_decode($response->getBody(), true);
    }

    public function getPullRequests($repoId)
    {
        $endpoint = "/repos/$repoId/pulls";
        return $this->makeRequest($endpoint);
    }

    public function getCommitDetails($repoId, $commitId)
    {
        $endpoint = "/repos/$repoId/commits/$commitId";
        return $this->makeRequest($endpoint);
    }
}
