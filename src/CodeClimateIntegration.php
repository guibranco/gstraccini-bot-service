<?php

class CodeClimateIntegration
{
    private $apiToken;
    private $apiUrl = 'https://api.codeclimate.com/v1/repos';

    public function __construct($apiToken)
    {
        $this->apiToken = $apiToken;
    }

    public function registerRepository($repoName, $vcsType = 'github', $vcsUrl)
    {
        $data = [
            'data' => [
                'type' => 'repos',
                'attributes' => [
                    'name' => $repoName,
                    'vcs_type' => $vcsType,
                    'vcs_url' => $vcsUrl
                ]
            ]
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Token token=' . $this->apiToken
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}

// Example usage:
$apiToken = 'your_code_climate_api_token';
$repoName = 'your_repo_name';
$vcsUrl = 'https://github.com/your_username/your_repo_name';

$ccIntegration = new CodeClimateIntegration($apiToken);
$response = $ccIntegration->registerRepository($repoName, 'github', $vcsUrl);
print_r($response);
