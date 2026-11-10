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
     *
     * @var Closure
     */
    private Closure $sleep;


    /**
     * Takes how to pause between two fetches.
     *
     * @param Closure $sleep Pauses for a number of seconds: `sleep(...)` in production.
     */
    public function __construct(Closure $sleep)
    {
        $this->sleep = $sleep;

    }//end __construct()


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
            $sha = $this->fetchHead($token, $pullRequestUrl);
            if ($sha !== null && $sha !== $previousSha) {
                return $sha;
            }

            if ($attempt < self::ATTEMPTS) {
                ($this->sleep)(self::DELAY_SECONDS);
            }
        }

        return null;

    }//end waitForNewHead()


    /**
     * Fetches the head commit of the pull request.
     *
     * @param string $token          The installation token.
     * @param string $pullRequestUrl The pull request endpoint, relative to the API root.
     *
     * @return string|null The head commit, or null if GitHub didn't answer with it.
     */
    private function fetchHead(string $token, string $pullRequestUrl): ?string
    {
        $response = doRequestGitHub($token, $pullRequestUrl, null, "GET");
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $pullRequest = json_decode($response->getBody());
        if (isset($pullRequest->head->sha) === false) {
            return null;
        }

        return $pullRequest->head->sha;

    }//end fetchHead()


}//end class
