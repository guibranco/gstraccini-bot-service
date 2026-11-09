<?php

namespace GuiBranco\GStracciniBot\Library;

use DateTimeImmutable;

/**
 * Reads, from a pull request's comments, where the bot stands with the
 * "recreate" request it posts when a Dependabot or Depfu pull request has
 * conflicts (`<!--GStraccini:{head sha}-->` followed by `@dependabot recreate`
 * or `@depfu recreate`).
 *
 * The comment being there is not proof the dependency bot received it. Both
 * Dependabot and Depfu acknowledge a command by reacting to its comment
 * (a 👍, within seconds) before they act on it, so a request they still
 * haven't reacted to long after it was posted never reached them: the pull
 * request keeps its head commit, the marker for that commit keeps matching,
 * and the conflict is never resolved. Such a request is posted again, a
 * limited number of times per head commit.
 *
 * Pure: no HTTP access of its own, the caller hands in the comments, the
 * time and the way to tell who reacted to a comment.
 */
class RecreateRequestAnalyzer
{
    /** Seconds a request may go without an acknowledgement before it counts as never received. */
    public const ACKNOWLEDGEMENT_TIMEOUT_SECONDS = 3600;


    /**
     * The marker that ties a request to the head commit it was posted for.
     * A recreate replaces that commit, so if the new one conflicts again its
     * request starts from scratch.
     *
     * @param string $headSha The pull request's head commit.
     *
     * @return string The HTML comment that opens the request.
     */
    public function marker(string $headSha): string
    {
        return "<!--GStraccini:{$headSha}-->";

    }//end marker()


    /**
     * Tells where the request for a head commit stands.
     *
     * @param array             $comments     Every comment of the pull request, as GitHub's REST API lists
     *                                        them (`body`, `created_at` and the `reactions` rollup are read).
     * @param string            $headSha      The pull request's current head commit.
     * @param DateTimeImmutable $now          The moment the requests are evaluated at.
     * @param callable          $reactedByBot `fn(object $comment): bool` telling whether the dependency bot
     *                                        is among those who reacted to a request. The rollup only has
     *                                        totals, so it is asked about the requests that have reactions.
     *
     * @return RecreateRequestStatus The status, how many requests were posted for the head commit, and
     *                               when the last one was.
     */
    public function analyze(array $comments, string $headSha, DateTimeImmutable $now, callable $reactedByBot): RecreateRequestStatus
    {
        $marker = $this->marker($headSha);
        $requests = 0;
        $acknowledged = false;
        $lastRequestedAt = null;

        foreach ($comments as $comment) {
            if ($this->isRequest($comment, $marker) === false) {
                continue;
            }

            $requests++;
            $acknowledged = ($acknowledged || $this->isAcknowledged($comment, $reactedByBot));
            $lastRequestedAt = $this->latest($lastRequestedAt, ($comment->created_at ?? null));
        }

        $timedOut = $this->hasTimedOut($lastRequestedAt, $now);

        return new RecreateRequestStatus($this->status($requests, $acknowledged, $timedOut), $requests, $lastRequestedAt);

    }//end analyze()


    /**
     * Whether a comment is a request for the head commit.
     *
     * @param object $comment A comment of the pull request.
     * @param string $marker  The marker of the head commit.
     *
     * @return bool
     */
    private function isRequest(object $comment, string $marker): bool
    {
        $body = ($comment->body ?? null);

        return is_string($body) === true && str_contains($body, $marker) === true;

    }//end isRequest()


    /**
     * Whether the dependency bot acknowledged a request. Any reaction of its
     * own counts: whatever it answered with, it saw the request, and asking
     * again would not change its answer. A reaction from someone else is not
     * its acknowledgement. A comment without the reactions rollup can't be
     * judged, so it is taken as acknowledged rather than posted again on a
     * guess.
     *
     * @param object   $comment      A request for the head commit.
     * @param callable $reactedByBot Tells whether the dependency bot reacted to a comment.
     *
     * @return bool
     */
    private function isAcknowledged(object $comment, callable $reactedByBot): bool
    {
        if (isset($comment->reactions->total_count) === false) {
            return true;
        }

        if ((int) $comment->reactions->total_count === 0) {
            return false;
        }

        return $reactedByBot($comment) === true;

    }//end isAcknowledged()


    /**
     * The later of two request dates, as GitHub writes them (ISO 8601, so
     * they sort as text).
     *
     * @param string|null $latest      The latest date so far, null when there is none.
     * @param mixed       $requestedAt The `created_at` of another request, ignored unless it is a string.
     *
     * @return string|null
     */
    private function latest(?string $latest, mixed $requestedAt): ?string
    {
        if (is_string($requestedAt) === false) {
            return $latest;
        }

        if ($latest !== null && strcmp($requestedAt, $latest) <= 0) {
            return $latest;
        }

        return $requestedAt;

    }//end latest()


    /**
     * Whether the last request is old enough to stop waiting for a reaction.
     * A request whose date can't be read is never timed out.
     *
     * @param string|null       $lastRequestedAt When the last request was posted, null when unknown.
     * @param DateTimeImmutable $now             The moment the requests are evaluated at.
     *
     * @return bool
     */
    private function hasTimedOut(?string $lastRequestedAt, DateTimeImmutable $now): bool
    {
        if ($lastRequestedAt === null) {
            return false;
        }

        $requestedAt = strtotime($lastRequestedAt);
        if ($requestedAt === false) {
            return false;
        }

        return ($now->getTimestamp() - $requestedAt) >= self::ACKNOWLEDGEMENT_TIMEOUT_SECONDS;

    }//end hasTimedOut()


    /**
     * The status the requests add up to.
     *
     * @param int  $requests     How many requests were posted for the head commit.
     * @param bool $acknowledged Whether the dependency bot acknowledged one of them.
     * @param bool $timedOut     Whether the last one is old enough to stop waiting.
     *
     * @return string One of the statuses of RecreateRequestStatus.
     */
    private function status(int $requests, bool $acknowledged, bool $timedOut): string
    {
        return match (true) {
            $requests === 0 => RecreateRequestStatus::NOT_REQUESTED,
            $acknowledged => RecreateRequestStatus::ACKNOWLEDGED,
            $timedOut === false => RecreateRequestStatus::WAITING,
            $requests >= RecreateRequestStatus::MAX_REQUESTS => RecreateRequestStatus::GAVE_UP,
            default => RecreateRequestStatus::UNACKNOWLEDGED,
        };

    }//end status()


}//end class
