<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Where the bot stands with the "recreate" request of a Dependabot or Depfu
 * pull request that has conflicts: whether a request was posted for the head
 * commit, how many times, and whether the dependency bot acknowledged one.
 */
class RecreateRequestStatus
{
    /** No request was posted for the head commit: the first one is posted. */
    public const NOT_REQUESTED = "not_requested";

    /** Requested less than the timeout ago and not acknowledged yet: wait. */
    public const WAITING = "waiting";

    /** The dependency bot reacted to a request for the head commit: nothing left to do. */
    public const ACKNOWLEDGED = "acknowledged";

    /** The last request timed out without an acknowledgement: it is posted again. */
    public const UNACKNOWLEDGED = "unacknowledged";

    /** Every request allowed for the head commit timed out without an acknowledgement: stop asking. */
    public const GAVE_UP = "gave_up";

    /** Requests posted for the same head commit before the bot stops asking. */
    public const MAX_REQUESTS = 3;

    /**
     * One of the statuses above.
     *
     * @var string
     */
    private string $status;

    /**
     * How many requests were already posted for the head commit.
     *
     * @var integer
     */
    private int $requests;

    /**
     * When the last of those requests was posted (GitHub's `created_at`),
     * null when there is none or its date is missing.
     *
     * @var string|null
     */
    private ?string $lastRequestedAt;


    /**
     * Describes the requests found for a head commit.
     *
     * @param string      $status          One of the statuses of this class.
     * @param int         $requests        How many requests were already posted.
     * @param string|null $lastRequestedAt When the last one was posted, null when unknown.
     */
    public function __construct(string $status, int $requests, ?string $lastRequestedAt)
    {
        $this->status = $status;
        $this->requests = $requests;
        $this->lastRequestedAt = $lastRequestedAt;

    }//end __construct()


    /**
     * The status of the request.
     *
     * @return string One of the statuses of this class.
     */
    public function status(): string
    {
        return $this->status;

    }//end status()


    /**
     * How many requests were already posted for the head commit.
     *
     * @return int
     */
    public function requests(): int
    {
        return $this->requests;

    }//end requests()


    /**
     * When the last request was posted.
     *
     * @return string|null GitHub's `created_at` of the last request, null when unknown.
     */
    public function lastRequestedAt(): ?string
    {
        return $this->lastRequestedAt;

    }//end lastRequestedAt()


    /**
     * Whether the request has to be posted now: for the first time, or again
     * because the last one was never acknowledged.
     *
     * @return bool
     */
    public function mustRequest(): bool
    {
        return in_array($this->status, [self::NOT_REQUESTED, self::UNACKNOWLEDGED], true);

    }//end mustRequest()


    /**
     * What happens with the request, for the processing log.
     *
     * @return string An empty string for a first request, which has nothing to add.
     */
    public function summary(): string
    {
        $attempt = ($this->requests + 1);
        $limit = self::MAX_REQUESTS;

        return match ($this->status) {
            self::ACKNOWLEDGED => "Already requested to recreate",
            self::WAITING => "Already requested to recreate, not acknowledged yet",
            self::GAVE_UP => "Recreate requested {$this->requests} times and never acknowledged, giving up",
            self::UNACKNOWLEDGED => "Recreate request from {$this->lastRequestedAt} was never acknowledged, requesting again ({$attempt}/{$limit})",
            default => "",
        };

    }//end summary()


    /**
     * The icon that closes the line of the processing log.
     *
     * @return string An empty string for a first request.
     */
    public function icon(): string
    {
        return match ($this->status) {
            self::ACKNOWLEDGED => "⚠️",
            self::WAITING => "⏳",
            self::GAVE_UP => "❌",
            self::UNACKNOWLEDGED => "🔁",
            default => "",
        };

    }//end icon()


    /**
     * What to warn about when a request was left without an acknowledgement.
     *
     * @return string An empty string when there is nothing to warn about.
     */
    public function warning(): string
    {
        return match ($this->status) {
            self::GAVE_UP => "still has conflicts — {$this->requests} recreate requests were never acknowledged, giving up",
            self::UNACKNOWLEDGED => "recreate request was never acknowledged — requesting again",
            default => "",
        };

    }//end warning()


    /**
     * The figures of the request, to go with a warning in the log.
     *
     * @return array The number of `requests` and when the last one was (`last_requested_at`).
     */
    public function context(): array
    {
        $context = ["requests" => $this->requests];
        $context["last_requested_at"] = $this->lastRequestedAt;

        return $context;

    }//end context()


}//end class
