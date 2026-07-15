<?php

namespace Src\Library;

use Src\Library\HttpClient;

class OpenAI
{
    private $httpClient;

    public function __construct(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function suggestLabels(string $title, string $body, array $labels): array
    {
        $prompt = "Suggest labels for the following issue: $title\n\nBody: $body\n\nAvailable labels: " . implode(', ', $labels);

        $response = $this->httpClient->post('https://api.openai.com/v1/completions', [
            'json' => [
                'model' => 'text-davinci-003',
                'prompt' => $prompt,
                'max_tokens' => 2048,
                'n' => 5,
                'stop' => ['\n\n']
            ]
        ]);

        $responseData = json_decode($response->getBody()->getContents(), true);

        $suggestedLabels = [];

        foreach ($responseData['choices'] as $choice) {
            $suggestedLabels[] = $choice['text'];
        }

        return $suggestedLabels;
    }
}