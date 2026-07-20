<?php

namespace GuiBranco\GStracciniBot\Library;

use RuntimeException;

/**
 * Pushes an empty ("dummy") commit onto a pull request branch via the GitHub
 * Git Data API, so a `+semver:` directive can be recorded without touching
 * any file content. This service does not use a local git checkout — it
 * only issues GitHub API calls.
 */
class VersionBumpCommitService
{
    public function createDummyCommit(array $metadata, string $branch, string $headSha, string $message): array
    {
        $commitUrl = "{$metadata['repoPrefix']}/git/commits/{$headSha}";
        $commitResponse = doRequestGitHub($metadata["token"], $commitUrl, null, "GET");
        if ($commitResponse->getStatusCode() !== 200) {
            throw new RuntimeException(
                "Failed to read head commit: [{$commitResponse->getStatusCode()}] " . $commitResponse->getBody()
            );
        }

        $commit = json_decode($commitResponse->getBody());
        $treeSha = $commit->tree->sha;

        $newCommitBody = [
            "message" => $message,
            "tree" => $treeSha,
            "parents" => [$headSha],
        ];
        $newCommitUrl = "{$metadata['repoPrefix']}/git/commits";
        $newCommitResponse = doRequestGitHub($metadata["token"], $newCommitUrl, $newCommitBody, "POST");
        if ($newCommitResponse->getStatusCode() !== 201) {
            throw new RuntimeException(
                "Failed to create commit: [{$newCommitResponse->getStatusCode()}] " . $newCommitResponse->getBody()
            );
        }

        $newCommit = json_decode($newCommitResponse->getBody());
        $newSha = $newCommit->sha;

        $refUrl = "{$metadata['repoPrefix']}/git/refs/heads/{$branch}";
        $refResponse = doRequestGitHub($metadata["token"], $refUrl, ["sha" => $newSha], "PATCH");
        if ($refResponse->getStatusCode() !== 200) {
            throw new RuntimeException(
                "Failed to update branch ref: [{$refResponse->getStatusCode()}] " . $refResponse->getBody()
            );
        }

        return ["sha" => $newSha];
    }
}
