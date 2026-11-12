<?php

namespace GuiBranco\GStracciniBot\Tests\Lib;

use GuiBranco\GStracciniBot\Tests\Handlers\LogStreamSpy;
use GuiBranco\Pancake\Response;
use PHPUnit\Framework\TestCase;

require_once sprintf("%s/../../lib/github.php", __DIR__);

/**
 * How handleResponse() logs what GitHub answers: errors, unless the caller
 * said it expects that status, in which case only at debug level.
 */
class HandleResponseTest extends TestCase
{
    /** What is logged for GitHub's 403 when rulesets aren't on the repository's plan. */
    private const NOT_ON_PLAN = "Invalid GitHub response. HTTP Status Code: 403 - Upgrade to GitHub Pro.";

    /**
     * What is sent to LogStream.
     *
     * @var LogStreamSpy
     */
    private LogStreamSpy $logStream;

    /**
     * What is sent to the legacy logger.
     *
     * @var LoggerSpy
     */
    private LoggerSpy $logger;


    /**
     * Logs to the spies.
     *
     * @return void
     */
    protected function setUp(): void
    {
        global $logger, $logStream;

        $this->logStream = new LogStreamSpy();
        $this->logger    = new LoggerSpy();
        $logStream       = $this->logStream;
        $logger          = $this->logger;

    }//end setUp()


    /**
     * Stops logging to the spies.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        global $logger, $logStream;

        $logger    = null;
        $logStream = null;

    }//end tearDown()


    /**
     * A 403 the caller expects is logged at debug level only.
     *
     * @return void
     */
    public function testAnExpectedStatusIsOnlyDebugged(): void
    {
        handleResponse($this->answer(403, "not-on-plan.json"), "GET", "application/vnd.github+json", [403]);

        $expected = sprintf("debug: GitHub API GET request returned an expected HTTP 403: %s", self::NOT_ON_PLAN);
        $this->assertSame([], $this->logger->messages());
        $this->assertSame([$expected], $this->logStream->lines());

    }//end testAnExpectedStatusIsOnlyDebugged()


    /**
     * A 403 the caller doesn't expect is still logged as an error, to both loggers.
     *
     * @return void
     */
    public function testAnUnexpectedStatusIsAnError(): void
    {
        handleResponse($this->answer(403, "not-on-plan.json"), "GET", "application/vnd.github+json", []);

        $expected = sprintf("error: GitHub API GET request failed with HTTP 403: %s", self::NOT_ON_PLAN);
        $this->assertSame([self::NOT_ON_PLAN], $this->logger->messages());
        $this->assertSame([$expected], $this->logStream->lines());

    }//end testAnUnexpectedStatusIsAnError()


    /**
     * The validation errors and the documentation link GitHub sends are part of the logged message.
     *
     * @return void
     */
    public function testTheErrorMessageKeepsGitHubsDetails(): void
    {
        handleResponse($this->answer(422, "validation-failed.json"), "POST", "application/vnd.github+json", []);

        $this->assertSame([$this->fixture("validation-failed.txt")], $this->logger->messages());

    }//end testTheErrorMessageKeepsGitHubsDetails()


    /**
     * An answer GitHub could give.
     *
     * @param int    $statusCode The status code.
     * @param string $fixture    The file holding the body.
     *
     * @return Response
     */
    private function answer(int $statusCode, string $fixture): Response
    {
        return new Response(false, $this->fixture($fixture), "", $statusCode, "url", null);

    }//end answer()


    /**
     * The content of a fixture file.
     *
     * @param string $name The file name.
     *
     * @return string
     */
    private function fixture(string $name): string
    {
        return file_get_contents(sprintf("%s/Fixtures/%s", __DIR__, $name));

    }//end fixture()


}//end class
