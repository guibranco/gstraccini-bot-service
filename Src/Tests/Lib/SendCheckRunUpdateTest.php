<?php

namespace GuiBranco\GStracciniBot\Tests\Lib;

use GuiBranco\Pancake\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once sprintf("%s/../../lib/github.php", __DIR__);

/**
 * How sendCheckRunUpdate() retries a check run update GitHub refused for a
 * transient reason (a 404 on a check run created a moment before, a 5xx, no
 * answer) before giving up.
 */
class SendCheckRunUpdateTest extends TestCase
{

    /**
     * The pauses taken between tries.
     *
     * @var integer[]
     */
    private array $pauses = [];


    /**
     * An update accepted at once is sent once, without pausing.
     *
     * @return void
     */
    public function testAnAcceptedUpdateIsSentOnce(): void
    {
        $sent = $this->sendAnswers([200]);

        $this->assertSame(1, $sent);
        $this->assertSame([], $this->pauses);

    }//end testAnAcceptedUpdateIsSentOnce()


    /**
     * A 404 right after the check run was created is sent again, and passes once GitHub finds it.
     *
     * @return void
     */
    public function testANotFoundIsRetried(): void
    {
        $sent = $this->sendAnswers([404, 200]);

        $this->assertSame(2, $sent);
        $this->assertSame([1], $this->pauses);

    }//end testANotFoundIsRetried()


    /**
     * Server errors and unanswered requests are retried too.
     *
     * @return void
     */
    public function testServerErrorsAndTimeoutsAreRetried(): void
    {
        $sent = $this->sendAnswers([502, -1, 200]);

        $this->assertSame(3, $sent);
        $this->assertSame([1, 2], $this->pauses);

    }//end testServerErrorsAndTimeoutsAreRetried()


    /**
     * An update still refused after the last try throws with GitHub's last answer.
     *
     * @return void
     */
    public function testAPersistentNotFoundThrowsAfterTheLastTry(): void
    {
        try {
            $this->sendAnswers([404, 404, 404, 404]);
            $this->fail("Expected a RuntimeException");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Failed to set check run #42 to success (HTTP 404)", $e->getMessage());
        }

        $this->assertSame([1, 2, 4], $this->pauses);

    }//end testAPersistentNotFoundThrowsAfterTheLastTry()


    /**
     * A refusal that won't change on retry (e.g. 422) throws at once.
     *
     * @return void
     */
    public function testANonTransientRefusalIsNotRetried(): void
    {
        try {
            $this->sendAnswers([422]);
            $this->fail("Expected a RuntimeException");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("(HTTP 422)", $e->getMessage());
        }

        $this->assertSame([], $this->pauses);

    }//end testANonTransientRefusalIsNotRetried()


    /**
     * Sends an update GitHub answers with the given status codes, in order.
     *
     * @param integer[] $statusCodes The status codes of each try.
     *
     * @return integer How many times the update was sent.
     */
    private function sendAnswers(array $statusCodes): int
    {
        $sent = 0;
        $send = function () use (&$sent, $statusCodes): Response {
            $statusCode = $statusCodes[$sent];
            $sent++;
            return new Response($statusCode === 200, '{"message":"Not Found"}', "", $statusCode, "url", null);
        };
        $pause = function (int $seconds): void {
            $this->pauses[] = $seconds;
        };

        try {
            sendCheckRunUpdate($send, $pause, 42, "success");
        } finally {
            $this->assertLessThanOrEqual(count($statusCodes), $sent);
        }

        return $sent;

    }//end sendAnswers()


}//end class
