<?php

namespace GuiBranco\GStracciniBot\Tests\Lib;

use GuiBranco\Pancake\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once sprintf("%s/../../lib/github.php", __DIR__);

/**
 * How ensureCheckRunUpdated() tells whether GitHub accepted a check run
 * update, so a failed PATCH doesn't leave the check run in progress.
 */
class EnsureCheckRunUpdatedTest extends TestCase
{


    /**
     * An accepted update goes through without an error.
     *
     * @return void
     */
    public function testAnAcceptedUpdatePasses(): void
    {
        ensureCheckRunUpdated($this->answer(200, "{}"), 42, "success");

        $this->addToAssertionCount(1);

    }//end testAnAcceptedUpdatePasses()


    /**
     * A refused update throws, with the check run, the conclusion and GitHub's answer.
     *
     * @return void
     */
    public function testARefusedUpdateThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to set check run #42 to success (HTTP 502): {"message":"Server Error"}');

        ensureCheckRunUpdated($this->answer(502, '{"message":"Server Error"}'), 42, "success");

    }//end testARefusedUpdateThrows()


    /**
     * A request that never got an answer (timeout, network error) throws too.
     *
     * @return void
     */
    public function testAnUnansweredUpdateThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to set check run #7 to failure (HTTP -1): ");

        ensureCheckRunUpdated($this->answer(-1, null), 7, "failure");

    }//end testAnUnansweredUpdateThrows()


    /**
     * An answer GitHub could give.
     *
     * @param integer     $statusCode The status code.
     * @param string|null $body       The body.
     *
     * @return Response
     */
    private function answer(int $statusCode, ?string $body): Response
    {
        return new Response($statusCode === 200, $body, "", $statusCode, "url", null);

    }//end answer()


}//end class
