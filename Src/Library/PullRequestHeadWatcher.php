<?php

namespace GuiBranco\GStracciniBot\Library;

use Closure;

/**
 * Waits for GitHub to report a new head commit on a pull request after the
 * bot pushed onto its branch (a version bump commit, an `.infisicalignore`
 * update, or `update-branch`).
 *
 * The pull request's `head.sha` doesn't move the instant the branch does —
 * `update-branch` in particular is asynchronous — so re-queuing the pull
 * request right away could have the worker run the checks on the old head
 * again (and, for `update-branch`, ask GitHub to update the branch again).
 */
class PullRequestHeadWatcher
{
    /** How many times the pull request is fetched, at most. */
    public const ATTEMPTS = 5;

    /** The pause between two fetches, in seconds. */
    public const DELAY_SECONDS = 2;

    /**
     * Pauses between two fetches: takes the number of seconds.
     */
    private Closure $sleep;

    public function __construct(?callable $sleep = null)
    {
        $this->sleep = Closure::fromCallable($sleep ?? 'sleep');
    }

    /**
     * Fetches the pull request until its head is no longer the given commit.
     *
     * @param string $token          The installation token.
     * @param string $pullRequestUrl The pull request endpoint, relative to the API root.
     * @param string $previousSha    The head commit before the bot pushed.
     *
     * @return string|null The new head commit, or null if it didn't move in time.
     */
    public function waitForNewHead(string $token, string $pullRequestUrl, string $previousSha): ?string
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $response = doRequestGitHub($token, $pullRequestUrl, null, "GET");
            if ($response->getStatusCode() === 200) {
                $sha = json_decode($response->getBody())->head->sha ?? null;
                if ($sha !== null && $sha !== $previousSha) {
                    return $sha;
                }
            }

            if ($attempt < self::ATTEMPTS) {
                ($this->sleep)(self::DELAY_SECONDS);
            }
        }

        return null;
    }
}
