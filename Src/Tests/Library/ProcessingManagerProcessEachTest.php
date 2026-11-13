<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\ProcessingManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How a batch goes on with its other items when one of them fails.
 */
class ProcessingManagerProcessEachTest extends TestCase
{


    /**
     * Every item is processed when none fails, and nothing is thrown.
     *
     * @return void
     */
    public function testEveryItemIsProcessed(): void
    {
        $processed = [];

        ProcessingManager::processEach([1, 2, 3], function ($item) use (&$processed): void {
            $processed[] = $item;
        });

        $this->assertSame([1, 2, 3], $processed);

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

        try {
            ProcessingManager::processEach([1, 2, 3, 4], function ($item) use (&$processed): void {
                $processed[] = $item;
                if ($item === 2 || $item === 3) {
                    throw new RuntimeException("item {$item} failed");
                }
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertSame([1, 2, 3, 4], $processed);
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
        ProcessingManager::processEach([], function (): void {
            $this->fail("No item to process");
        });

        $this->addToAssertionCount(1);

    }//end testAnEmptyBatchDoesNothing()


}//end class
