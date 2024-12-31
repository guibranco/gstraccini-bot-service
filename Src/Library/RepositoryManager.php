<?php

namespace GuiBranco\GStracciniBot\Library;

class RepositoryManager
{
    public function getBotOptions(string $token, string $repositoryOwner, string $repositoryName): array
    {
        $paths = array("/", "/.github/");
        $fileContentResponse = null;
        foreach ($paths as $path) {
            $fileContentResponse = doRequestGitHub($token, "repos/{$repositoryOwner}/{$repositoryName}/contents" . $path . ".gstraccini.toml", null, "GET");
            if ($fileContentResponse->getStatusCode() === 200) {
                break;
            }
        }

        if ($fileContentResponse === null) {
            return $this->getDefaultBotOptions();
        }

        $fileContent = json_decode($fileContentResponse->getBody(), true);
        return $this->getDefaultBotOptions();
    }

    private function getDefaultBotOptions(): array
    {
        return array("labels" => array("style" => "icons", "categories" => ["all"]));
    }

    public function getLabels(string $token, string $repositoryOwner, string $repositoryName): array
    {
        $labelsResponse = doRequestGitHub($token, "repos/{$repositoryOwner}/{$repositoryName}/labels", null, "GET");
        if ($labelsResponse->getStatusCode() !== 200) {
            return array();
        }

        return json_decode($labelsResponse->getBody(), true);
    }

    public function getLanguages(string $token, string $repositoryOwner, string $repositoryName): array
    {
        $languagesResponse = doRequestGitHub($token, "repos/{$repositoryOwner}/{$repositoryName}/languages", null, "GET");
        if ($languagesResponse->getStatusCode() !== 200) {
            return array();
        }

        return json_decode($languagesResponse->getBody(), true);
    }
}
