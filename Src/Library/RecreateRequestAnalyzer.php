<?php

namespace GuiBranco\GStracciniBot\Library;

use DateTimeImmutable;

/**
 * Reads, from a pull request's comments, where the bot stands with the
 * "recreate" request it posts when a Dependabot or Depfu pull request has
 * conflicts (`<!--GStraccini:{head sha}-->` followed by `@dependabot recreate`
 * or `@depfu recreate`), and says whether the request has to be posted.
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

    /** Requests posted for the same head commit before the bot stops asking. */
    public const MAX_REQUESTS = 3;

    /** No request was posted for the head commit: post the first one. */
    public const STATUS_NOT_REQUESTED = "not_requested";

    /** Requested less than the timeout ago and not acknowledged yet: wait. */
    public const STATUS_WAITING = "waiting";

    /** The dependency bot reacted to a request for the head commit: nothing left to do. */
    public const STATUS_ACKNOWLEDGED = "acknowledged";

    /** The last request timed out without an acknowledgement: post it again. */
    public const STATUS_UNACKNOWLEDGED = "unacknowledged";

    /** MAX_REQUESTS requests timed out without an acknowledgement: stop asking. */
    public const STATUS_GAVE_UP = "gave_up";

    /**
     * The marker that ties a request to the head commit it was posted for.
     * A recreate replaces that commit, so if the new one conflicts again its
     * request starts from scratch.
     */
    public function marker(string $headSha): string
    {
        return "<!--GStraccini:{$headSha}-->";
    }

    /**
     * @param array<int, object> $comments Every comment of the pull request, as GitHub's REST API lists them
     *                                     (`body`, `created_at` and the `reactions` rollup are read).
     * @param string             $headSha  The pull request's current head commit.
     * @param DateTimeImmutable  $now      The moment the requests are evaluated at.
     * @param callable|null      $reactedByBot `fn(object $comment): bool` telling whether the dependency
     *                                     bot is among those who reacted to a request. The rollup only has totals,
     *                                     so it is asked about the requests that have reactions; without it, a
     *                                     reaction from anyone counts.
     *
     * @return array{status: string, requests: int, lastRequestedAt: ?string} One of the STATUS_* values, how many
     *         requests were posted for the head commit, and when the last one was (GitHub's `created_at`).
     */
    public function analyze(array $comments, string $headSha, DateTimeImmutable $now, ?callable $reactedByBot = null): array
    {
        $marker = $this->marker($headSha);
        $requests = 0;
        $acknowledged = false;
        $lastRequestedAt = null;

        foreach ($comments as $comment) {
            $body = $comment->body ?? null;
            if (!is_string($body) || !str_contains($body, $marker)) {
                continue;
            }

            $requests++;
            $acknowledged = $acknowledged || $this->isAcknowledged($comment, $reactedByBot);

            $requestedAt = $comment->created_at ?? null;
            if (is_string($requestedAt) && ($lastRequestedAt === null || strcmp($requestedAt, $lastRequestedAt) > 0)) {
                $lastRequestedAt = $requestedAt;
            }
        }

        $status = match (true) {
            $requests === 0 => self::STATUS_NOT_REQUESTED,
            $acknowledged => self::STATUS_ACKNOWLEDGED,
            !$this->hasTimedOut($lastRequestedAt, $now) => self::STATUS_WAITING,
            $requests >= self::MAX_REQUESTS => self::STATUS_GAVE_UP,
            default => self::STATUS_UNACKNOWLEDGED,
        };

        return ["status" => $status, "requests" => $requests, "lastRequestedAt" => $lastRequestedAt];
    }

    /**
     * Any reaction of the dependency bot counts: whatever it answered with,
     * it saw the request, and asking again would not change its answer. A
     * reaction from someone else is not its acknowledgement. A comment
     * without the reactions rollup can't be judged, so it is taken as
     * acknowledged rather than posted again on a guess.
     */
    private function isAcknowledged(object $comment, ?callable $reactedByBot): bool
    {
        if (!isset($comment->reactions->total_count)) {
            return true;
        }

        if ((int) $comment->reactions->total_count === 0) {
            return false;
        }

        return $reactedByBot === null || $reactedByBot($comment) === true;
    }

    /**
     * Whether the last request is old enough to stop waiting for a reaction.
     * A request whose date can't be read is never timed out.
     */
    private function hasTimedOut(?string $lastRequestedAt, DateTimeImmutable $now): bool
    {
        $requestedAt = $lastRequestedAt === null ? false : strtotime($lastRequestedAt);
        if ($requestedAt === false) {
            return false;
        }

        return $now->getTimestamp() - $requestedAt >= self::ACKNOWLEDGEMENT_TIMEOUT_SECONDS;
    }
}
