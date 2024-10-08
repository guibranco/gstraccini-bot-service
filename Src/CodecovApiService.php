<?php

namespace Src;

class CodecovApiService
{
    private $apiBaseUrl = 'https://api.codecov.io/v2';
    private $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    private function makeRequest($endpoint)
    {
        $url = $this->apiBaseUrl . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json'
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new \Exception('Request Error: ' . curl_error($ch));
        }
        curl_close($ch);
        return json_decode($response, true);
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
