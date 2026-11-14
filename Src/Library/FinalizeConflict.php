<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Why an item could not be finalized (moved from PROCESSING to PROCESSED)
 * once its handler returned, worked out from the ProcessingState the row
 * holds right after the failed update.
 *
 * Most of the time the row was handed back to the queue while it was being
 * processed: a webhook event for the same pull request / issue (often one
 * the bot itself caused — editing the description, approving, updating the
 * branch) or the handler re-queuing the item on purpose. That is the queue
 * working as designed — the item runs again with the newer state — and not
 * worth a warning. Only the other cases point at two processes working on
 * the same item.
 */
enum FinalizeConflict: string
{
    /** A newer event (or the handler itself) re-queued the item; it will be processed again. */
    case Requeued = 'requeued';

    /** Another process already moved the item to PROCESSED. */
    case FinalizedElsewhere = 'finalized_elsewhere';

    /** The item is PROCESSING again — another process claimed it after it went stale. */
    case ClaimedElsewhere = 'claimed_elsewhere';

    /** The row no longer exists. */
    case Missing = 'missing';

    /** States a row is in while it waits in the queue (see readTable()). */
    private const QUEUED_STATES = ['NEW', 'RE_REQUESTED', 'UPDATED', 'IDLE'];


    /**
     * Classifies the conflict from the row's current ProcessingState.
     *
     * @param string|null $state The ProcessingState read back, null when the row is gone.
     *
     * @return self The conflict.
     */
    public static function fromState(?string $state): self
    {
        if ($state === null) {
            return self::Missing;
        }

        $state = strtoupper($state);
        if (in_array($state, self::QUEUED_STATES, true)) {
            return self::Requeued;
        }

        if ($state === 'PROCESSING') {
            return self::ClaimedElsewhere;
        }

        return self::FinalizedElsewhere;

    }//end fromState()


    /**
     * Whether the conflict is the expected outcome of the queue rather than an anomaly.
     *
     * @return boolean True when the item was simply re-queued.
     */
    public function isExpected(): bool
    {
        return $this === self::Requeued;

    }//end isExpected()


    /**
     * The LogStream message template describing the conflict.
     *
     * @return string The template; {item} and {state} are context keys.
     */
    public function messageTemplate(): string
    {
        return match ($this) {
            self::Requeued => 'Item re-queued while processing {item} (now {state}) — it will be processed again',
            self::FinalizedElsewhere => 'Item finalized by another process {item}',
            self::ClaimedElsewhere => 'Item claimed by another process while processing {item}',
            self::Missing => 'Item no longer exists when finalizing {item}',
        };

    }//end messageTemplate()
}
