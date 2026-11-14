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
     * @return array<string, array{string}>
     */
    public static function queuedStates(): array
    {
        return [
            'new' => ['NEW'],
            're-requested' => ['RE_REQUESTED'],
            'updated' => ['UPDATED'],
            'idle' => ['IDLE'],
            'lower case' => ['re_requested'],
        ];

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
        $conflict = FinalizeConflict::fromState($state);

        $this->assertSame(FinalizeConflict::Requeued, $conflict);
        $this->assertTrue($conflict->isExpected());

    }//end testQueuedStateIsAnExpectedRequeue()


    /**
     * A row another process already finalized is a real conflict.
     *
     * @return void
     */
    public function testProcessedIsFinalizedElsewhere(): void
    {
        $conflict = FinalizeConflict::fromState('PROCESSED');

        $this->assertSame(FinalizeConflict::FinalizedElsewhere, $conflict);
        $this->assertFalse($conflict->isExpected());
        $this->assertSame('Item finalized by another process {item}', $conflict->messageTemplate());

    }//end testProcessedIsFinalizedElsewhere()


    /**
     * A row PROCESSING again was claimed by another process after going stale.
     *
     * @return void
     */
    public function testProcessingIsClaimedElsewhere(): void
    {
        $conflict = FinalizeConflict::fromState('PROCESSING');

        $this->assertSame(FinalizeConflict::ClaimedElsewhere, $conflict);
        $this->assertFalse($conflict->isExpected());

    }//end testProcessingIsClaimedElsewhere()


    /**
     * A row that's gone is reported as missing.
     *
     * @return void
     */
    public function testMissingRow(): void
    {
        $conflict = FinalizeConflict::fromState(null);

        $this->assertSame(FinalizeConflict::Missing, $conflict);
        $this->assertFalse($conflict->isExpected());

    }//end testMissingRow()
}
