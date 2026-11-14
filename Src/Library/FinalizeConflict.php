<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Why an item could not be finalized (moved from PROCESSING to PROCESSED)
 * once its handler returned, worked out from the ProcessingState the row
 * holds right after the failed update.
 *
 * Most of the time the row was handed back to the queue while it was being
 * processed: a webhook event for the same pull request or issue (often one
 * the bot itself caused, by editing the description, approving or updating
 * the branch) or the handler re-queuing the item on purpose. That is the
 * queue working as designed, the item runs again with the newer state, and
 * not worth a warning. Only the other cases point at two processes working
 * on the same item.
 */
class FinalizeConflict
{
    public const REQUEUED = 'requeued';

    public const FINALIZED_ELSEWHERE = 'finalized_elsewhere';

    public const CLAIMED_ELSEWHERE = 'claimed_elsewhere';

    public const MISSING = 'missing';

    /**
     * The conflict, one of the constants of this class.
     *
     * @var string
     */
    private string $kind;


    /**
     * Classifies the conflict from the row's current ProcessingState.
     *
     * @param string|null $state The ProcessingState read back, null when the row is gone.
     */
    public function __construct(?string $state)
    {
        $this->kind = $this->classify($state);

    }//end __construct()


    /**
     * The conflict, one of the constants of this class.
     *
     * @return string The conflict.
     */
    public function getKind(): string
    {
        return $this->kind;

    }//end getKind()


    /**
     * Whether the conflict is the expected outcome of the queue rather than an anomaly.
     *
     * @return boolean True when the item was simply re-queued.
     */
    public function isExpected(): bool
    {
        return $this->kind === self::REQUEUED;

    }//end isExpected()


    /**
     * The LogStream level to report the conflict with: info when the item was
     * simply re-queued, warning when two processes worked on the same item.
     *
     * @return string The level.
     */
    public function getLogLevel(): string
    {
        if ($this->isExpected() === true) {
            return 'info';
        }

        return 'warning';

    }//end getLogLevel()


    /**
     * The LogStream message template describing the conflict.
     *
     * @return string The template, {item} and {state} being context keys.
     */
    public function getMessageTemplate(): string
    {
        $templates = [];
        $templates[self::REQUEUED] = 'Item re-queued while processing {item} (now {state}), it will be processed again';
        $templates[self::FINALIZED_ELSEWHERE] = 'Item finalized by another process {item}';
        $templates[self::CLAIMED_ELSEWHERE] = 'Item claimed by another process while processing {item}';
        $templates[self::MISSING] = 'Item no longer exists when finalizing {item}';

        return $templates[$this->kind];

    }//end getMessageTemplate()


    /**
     * Works out the conflict from the ProcessingState.
     *
     * @param string|null $state The ProcessingState read back, null when the row is gone.
     *
     * @return string The conflict.
     */
    private function classify(?string $state): string
    {
        if ($state === null) {
            return self::MISSING;
        }

        $normalized = strtoupper($state);
        if ($this->isQueuedState($normalized) === true) {
            return self::REQUEUED;
        }

        return $this->classifyNotQueued($normalized);

    }//end classify()


    /**
     * Works out the conflict of a row that isn't waiting in the queue.
     *
     * @param string $state The upper-case ProcessingState.
     *
     * @return string The conflict.
     */
    private function classifyNotQueued(string $state): string
    {
        if ($state === 'PROCESSING') {
            return self::CLAIMED_ELSEWHERE;
        }

        return self::FINALIZED_ELSEWHERE;

    }//end classifyNotQueued()


    /**
     * Whether the state is one a row waits in the queue with (see readTable()).
     *
     * @param string $state The upper-case ProcessingState.
     *
     * @return boolean True when the row is queued.
     */
    private function isQueuedState(string $state): bool
    {
        $queued = [];
        $queued[] = 'NEW';
        $queued[] = 'RE_REQUESTED';
        $queued[] = 'UPDATED';
        $queued[] = 'IDLE';

        return in_array($state, $queued, true);

    }//end isQueuedState()


}//end class
