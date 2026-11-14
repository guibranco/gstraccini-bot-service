<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\FinalizeConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How an item that couldn't be finalized is classified from its current state.
 */
class FinalizeConflictTest extends TestCase
{


    /**
     * The states a row waits in the queue with.
     *
     * @return array The ProcessingState of each case, by case name.
     */
    public static function queuedStates(): array
    {
        $states = [];
        $states['new'] = ['NEW'];
        $states['re-requested'] = ['RE_REQUESTED'];
        $states['updated'] = ['UPDATED'];
        $states['idle'] = ['IDLE'];
        $states['lower case'] = ['re_requested'];

        return $states;

    }//end queuedStates()


    /**
     * A row handed back to the queue while processing is expected, not a warning.
     *
     * @param string $state The ProcessingState read back.
     *
     * @return void
     */
    #[DataProvider('queuedStates')]
    public function testQueuedStateIsAnExpectedRequeue(string $state): void
    {
        $conflict = new FinalizeConflict($state);

        $this->assertSame(FinalizeConflict::REQUEUED, $conflict->getKind());
        $this->assertTrue($conflict->isExpected());
        $this->assertSame('info', $conflict->getLogLevel());

    }//end testQueuedStateIsAnExpectedRequeue()


    /**
     * A row another process already finalized is a real conflict, logged as before.
     *
     * @return void
     */
    public function testProcessedIsFinalizedElsewhere(): void
    {
        $conflict = new FinalizeConflict('PROCESSED');

        $this->assertSame(FinalizeConflict::FINALIZED_ELSEWHERE, $conflict->getKind());
        $this->assertFalse($conflict->isExpected());
        $this->assertSame('warning', $conflict->getLogLevel());
        $this->assertSame('Item finalized by another process {item}', $conflict->getMessageTemplate());

    }//end testProcessedIsFinalizedElsewhere()


    /**
     * A row PROCESSING again was claimed by another process after going stale.
     *
     * @return void
     */
    public function testProcessingIsClaimedElsewhere(): void
    {
        $conflict = new FinalizeConflict('PROCESSING');

        $this->assertSame(FinalizeConflict::CLAIMED_ELSEWHERE, $conflict->getKind());
        $this->assertFalse($conflict->isExpected());

    }//end testProcessingIsClaimedElsewhere()


    /**
     * A row that's gone is reported as missing.
     *
     * @return void
     */
    public function testMissingRow(): void
    {
        $conflict = new FinalizeConflict(null);

        $this->assertSame(FinalizeConflict::MISSING, $conflict->getKind());
        $this->assertFalse($conflict->isExpected());

    }//end testMissingRow()


}//end class
