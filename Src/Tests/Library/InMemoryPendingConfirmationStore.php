<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\DatabaseException;
use GuiBranco\GStracciniBot\Library\PendingConfirmation;
use GuiBranco\GStracciniBot\Library\PendingConfirmationStore;

/**
 * Pending confirmation store whose table is an in-memory list of calls and rows.
 */
class InMemoryPendingConfirmationStore extends PendingConfirmationStore
{
    /** @var PendingConfirmation[] The recorded confirmations. */
    public array $recorded = [];

    /** @var PendingConfirmation[] What findDueForReminder() returns. */
    public array $due = [];

    /** @var array<int, string> Every call made, as `method arg1 arg2 …`. */
    public array $calls = [];

    /** Whether every call fails as an unreachable database would. */
    public bool $databaseDown = false;

    public function record(PendingConfirmation $confirmation): void
    {
        $this->log("record", $confirmation->kind, $confirmation->commentId);
        $this->recorded[] = $confirmation;
    }

    public function resolveByComment(int $commentId, string $resolution): int
    {
        $this->log("resolveByComment", $commentId, $resolution);
        return 1;
    }

    public function resolveForPullRequest(string $owner, string $repo, int $number, string $kind, string $resolution): int
    {
        $this->log("resolveForPullRequest", "{$owner}/{$repo}#{$number}", $kind, $resolution);
        return 1;
    }

    public function cancelForPullRequest(string $owner, string $repo, int $number, string $resolution): int
    {
        $this->log("cancelForPullRequest", "{$owner}/{$repo}#{$number}", $resolution);
        return 1;
    }

    public function cancelByComment(int $commentId, string $resolution): int
    {
        $this->log("cancelByComment", $commentId, $resolution);
        return 1;
    }

    public function findDueForReminder(int $intervalSeconds, int $maxReminders, int $limit): array
    {
        $this->log("findDueForReminder", $intervalSeconds, $maxReminders, $limit);
        return $this->due;
    }

    public function markReminded(int $sequence): void
    {
        $this->log("markReminded", $sequence);
    }

    private function log(string $method, mixed ...$args): void
    {
        if ($this->databaseDown) {
            throw new DatabaseException("Failed to connect to MySQL");
        }

        $this->calls[] = trim($method . " " . implode(" ", array_map("strval", $args)));
    }
}
