<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\BatchProcessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How a batch goes on with its other items when one of them fails.
 */
class BatchProcessorTest extends TestCase
{


    /**
     * Every item is processed when none fails, and nothing is thrown.
     *
     * @return void
     */
    public function testEveryItemIsProcessed(): void
    {
        $processed = [];
        $items = range(1, 3);
        $processOne = function ($item) use (&$processed): void {
            $processed[] = $item;
        };

        (new BatchProcessor())->processEach($items, $processOne);

        $this->assertSame($items, $processed);

    }//end testEveryItemIsProcessed()


    /**
     * A failing item doesn't keep the items after it from being processed,
     * and the first failure is rethrown at the end.
     *
     * @return void
     */
    public function testAFailingItemDoesNotStopTheBatch(): void
    {
        $processed = [];
        $caught = null;
        $items = range(1, 4);
        $processOne = function ($item) use (&$processed): void {
            $processed[] = $item;
            if ($item === 2 || $item === 3) {
                throw new RuntimeException(sprintf("item %d failed", $item));
            }
        };

        try {
            (new BatchProcessor())->processEach($items, $processOne);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertSame($items, $processed);
        $this->assertNotNull($caught);
        $this->assertSame("item 2 failed", $caught->getMessage());

    }//end testAFailingItemDoesNotStopTheBatch()


    /**
     * An empty batch does nothing.
     *
     * @return void
     */
    public function testAnEmptyBatchDoesNothing(): void
    {
        $processOne = function (): void {
            $this->fail("No item to process");
        };

        (new BatchProcessor())->processEach([], $processOne);

        $this->addToAssertionCount(1);

    }//end testAnEmptyBatchDoesNothing()


}//end class
