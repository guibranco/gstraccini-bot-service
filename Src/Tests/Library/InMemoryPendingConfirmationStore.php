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

    /**
     * The recorded confirmations.
     *
     * @var PendingConfirmation[]
     */
    public array $recorded = [];

    /**
     * What findDueForReminder() returns.
     *
     * @var PendingConfirmation[]
     */
    public array $due = [];

    /**
     * Every call made, as `method arg1 arg2 …`.
     *
     * @var string[]
     */
    public array $calls = [];

    /**
     * Whether every call fails as an unreachable database would.
     *
     * @var boolean
     */
    public bool $databaseDown = false;


    /**
     * Keeps the confirmation.
     *
     * @param PendingConfirmation $confirmation The confirmation.
     *
     * @return void
     */
    public function record(PendingConfirmation $confirmation): void
    {
        $this->log('record', $confirmation->kind, $confirmation->commentId);
        $this->recorded[] = $confirmation;

    }//end record()


    /**
     * Logs the call.
     *
     * @param integer $commentId  The bot's comment.
     * @param string  $resolution What was decided.
     *
     * @return integer
     */
    public function resolveByComment(int $commentId, string $resolution): int
    {
        $this->log('resolveByComment', $commentId, $resolution);
        return 1;

    }//end resolveByComment()


    /**
     * Logs the call.
     *
     * @param string  $owner      Repository owner.
     * @param string  $repo       Repository name.
     * @param integer $number     Pull request number.
     * @param string  $kind       The kind.
     * @param string  $resolution Why.
     *
     * @return integer
     */
    public function resolveForPullRequest(string $owner, string $repo, int $number, string $kind, string $resolution): int
    {
        $this->log('resolveForPullRequest', "{$owner}/{$repo}#{$number}", $kind, $resolution);
        return 1;

    }//end resolveForPullRequest()


    /**
     * Logs the call.
     *
     * @param string  $owner      Repository owner.
     * @param string  $repo       Repository name.
     * @param integer $number     Pull request number.
     * @param string  $resolution Why.
     *
     * @return integer
     */
    public function cancelForPullRequest(string $owner, string $repo, int $number, string $resolution): int
    {
        $this->log('cancelForPullRequest', "{$owner}/{$repo}#{$number}", $resolution);
        return 1;

    }//end cancelForPullRequest()


    /**
     * Logs the call.
     *
     * @param integer $commentId  The bot's comment.
     * @param string  $resolution Why.
     *
     * @return integer
     */
    public function cancelByComment(int $commentId, string $resolution): int
    {
        $this->log('cancelByComment', $commentId, $resolution);
        return 1;

    }//end cancelByComment()


    /**
     * Returns the confirmations set up as due.
     *
     * @param integer $intervalSeconds How long to wait before a reminder.
     * @param integer $maxReminders    How many reminders at most.
     * @param integer $limit           How many rows at most.
     *
     * @return PendingConfirmation[]
     */
    public function findDueForReminder(int $intervalSeconds, int $maxReminders, int $limit): array
    {
        $this->log('findDueForReminder', $intervalSeconds, $maxReminders, $limit);
        return $this->due;

    }//end findDueForReminder()


    /**
     * Logs the call.
     *
     * @param integer $sequence The row.
     * @param boolean $posted   Whether a reminder was posted.
     *
     * @return void
     */
    public function markReminded(int $sequence, bool $posted=true): void
    {
        $method = 'deferReminder';
        if ($posted === true) {
            $method = 'markReminded';
        }

        $this->log($method, $sequence);

    }//end markReminded()


    /**
     * Records a call, or fails while the database is down.
     *
     * @param string $method  The method called.
     * @param mixed  ...$args Its arguments.
     *
     * @return void
     *
     * @throws DatabaseException While the database is down.
     */
    private function log(string $method, mixed ...$args): void
    {
        if ($this->databaseDown === true) {
            throw new DatabaseException('Failed to connect to MySQL');
        }

        $this->calls[] = trim($method.' '.implode(' ', array_map('strval', $args)));

    }//end log()


}//end class
