<?php

namespace GuiBranco\GStracciniBot\Library;

use RuntimeException;

class InfisicalIgnoreCommitService
{
    private const FILE_PATH = ".infisicalignore";
    private const MAX_ATTEMPTS = 2;

    public function applyToPullRequest(array $metadata, string $branch, array $newLines): array
    {
        $fileUpdater = new InfisicalIgnoreFileUpdater();
        $contentsUrl = "{$metadata['repoPrefix']}/contents/" . self::FILE_PATH;

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            [$existingContent, $sha] = $this->readFile($metadata, $contentsUrl, $branch);
            $mergedContent = $fileUpdater->merge($existingContent, $newLines);

            if ($existingContent !== null && $mergedContent === $existingContent) {
                return [
                    "sha" => null,
                    "message" => "No new entries to add — `.infisicalignore` already up to date.",
                ];
            }

            $body = [
                "message" => "Add suggested .infisicalignore entries",
                "content" => base64_encode($mergedContent),
                "branch" => $branch,
            ];
            if ($sha !== null) {
                $body["sha"] = $sha;
            }

            $response = doRequestGitHub($metadata["token"], $contentsUrl, $body, "PUT");
            $statusCode = $response->getStatusCode();

            if ($statusCode === 200 || $statusCode === 201) {
                $result = json_decode($response->getBody(), true);
                return [
                    "sha" => $result["commit"]["sha"] ?? null,
                    "message" => $body["message"],
                ];
            }

            if (($statusCode === 409 || $statusCode === 422) && $attempt < self::MAX_ATTEMPTS) {
                $lastError = $response->getBody();
                continue;
            }

            throw new RuntimeException(
                "Failed to update .infisicalignore: [{$statusCode}] " . $response->getBody()
            );
        }

        throw new RuntimeException("Failed to update .infisicalignore after retry: " . $lastError);
    }

    private function readFile(array $metadata, string $contentsUrl, string $branch): array
    {
        $url = $contentsUrl . "?ref=" . urlencode($branch);
        $response = doRequestGitHub($metadata["token"], $url, null, "GET");

        if ($response->getStatusCode() !== 200) {
            return [null, null];
        }

        $fileData = json_decode($response->getBody(), true);
        if (!isset($fileData["content"]) || ($fileData["encoding"] ?? null) !== "base64") {
            return [null, null];
        }

        return [base64_decode($fileData["content"]), $fileData["sha"] ?? null];
    }
}
