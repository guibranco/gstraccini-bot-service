<?php

namespace GuiBranco\GStracciniBot\Library;

use RuntimeException;

/**
 * Pushes the commit bumping the major version of a breaking change pull
 * request, through the GitHub API only:
 *
 * - with AppVeyor, the `appveyor.yml` update (carrying `+semver: major` too
 *   when the repository also uses GitVersion);
 * - with GitVersion only, an empty commit carrying `+semver: major`.
 */
class BreakingChangeCommitService
{
    public const GITVERSION_DIRECTIVE = "+semver: major";


    /**
     * Pushes the commit the plan needs onto the pull request branch.
     *
     * @param array              $metadata Metadata for the GitHub API requests (`token`, `repoPrefix`).
     * @param string             $branch   The pull request head branch.
     * @param string             $headSha  The pull request head commit.
     * @param BreakingChangePlan $plan     What the major version bump needs.
     *
     * @return string|null The sha of the commit pushed, null when the plan needs no commit.
     *
     * @throws RuntimeException When GitHub refuses the commit.
     */
    public function push(array $metadata, string $branch, string $headSha, BreakingChangePlan $plan): ?string
    {
        if ($plan->needsAppVeyorBump() === true) {
            return $this->updateAppVeyorFile($metadata, $branch, $plan);
        }

        if ($plan->needsGitVersionBump() === false) {
            return null;
        }

        $message = sprintf("Apply requested major version bump (breaking change)\n\n%s", self::GITVERSION_DIRECTIVE);
        $result  = (new VersionBumpCommitService())->createDummyCommit($metadata, $branch, $headSha, $message);

        return $result["sha"];

    }//end push()


    /**
     * Commits the AppVeyor file with the next major onto the branch.
     *
     * @param array              $metadata Metadata for the GitHub API requests.
     * @param string             $branch   The pull request head branch.
     * @param BreakingChangePlan $plan     What the major version bump needs.
     *
     * @return string The sha of the commit.
     *
     * @throws RuntimeException When GitHub refuses the update.
     */
    private function updateAppVeyorFile(array $metadata, string $branch, BreakingChangePlan $plan): string
    {
        $message = "Bump the AppVeyor version to {$plan->appVeyorTo} (breaking change)";
        if ($plan->needsGitVersionBump() === true) {
            $message .= sprintf("\n\n%s", self::GITVERSION_DIRECTIVE);
        }

        $body            = [];
        $body["message"] = $message;
        $body["content"] = base64_encode((string) $plan->appVeyorNewContent);
        $body["sha"]     = $plan->appVeyorFileSha;
        $body["branch"]  = $branch;

        $url      = "{$metadata["repoPrefix"]}/contents/{$plan->appVeyorPath}";
        $response = doRequestGitHub($metadata["token"], $url, $body, "PUT");
        $status   = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException("Failed to update {$plan->appVeyorPath}: [{$status}] {$response->getBody()}");
        }

        $result = json_decode((string) $response->getBody());
        $sha    = ($result->commit->sha ?? null);
        if (is_string($sha) === false) {
            throw new RuntimeException("Failed to update {$plan->appVeyorPath}: no commit returned");
        }

        return $sha;

    }//end updateAppVeyorFile()


}//end class
