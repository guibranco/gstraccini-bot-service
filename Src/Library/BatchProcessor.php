<?php

namespace GuiBranco\GStracciniBot\Library;

use Throwable;

/**
 * Processes the items of a worker batch so that one failing item doesn't keep
 * the others from being processed.
 */
class BatchProcessor
{


    /**
     * Processes every item of a batch, even when one of them fails: readTable() marks the whole
     * batch as PROCESSING, so an item left behind by an earlier failure would wait an hour before
     * being picked up again. The first failure is rethrown once every item had its turn, so the
     * batch is still reported as failed (processItem() already logged each failed item).
     *
     * @param array    $items      The items of the batch.
     * @param callable $processOne Processes one item; throws when it fails.
     *
     * @return void
     *
     * @throws Throwable The first item failure, after the whole batch was processed.
     */
    public function processEach(array $items, callable $processOne): void
    {
        $firstFailure = null;
        foreach ($items as $item) {
            try {
                $processOne($item);
            } catch (Throwable $e) {
                $firstFailure = ($firstFailure ?? $e);
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }

    }//end processEach()


}//end class
