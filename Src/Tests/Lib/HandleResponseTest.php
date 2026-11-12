<?php

namespace GuiBranco\GStracciniBot\Tests\Lib;

use GuiBranco\GStracciniBot\Tests\Handlers\LogStreamSpy;
use GuiBranco\Pancake\Response;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/../../lib/github.php";

/**
 * How handleResponse() logs what GitHub answers: errors, unless the caller
 * said it expects that status, in which case only at debug level.
 */
class HandleResponseTest extends TestCase
{
    /** The body of GitHub's answer when rulesets aren't on the repository's plan. */
    private const NOT_ON_PLAN = '{"message":"Upgrade to GitHub Pro to enable this feature."}';

    /**
     * What is sent to LogStream.
     *
     * @var LogStreamSpy
     */
    private LogStreamSpy $logStream;

    /**
     * The messages sent to the legacy logger.
     *
     * @var array
     */
    private array $logged = [];


    /**
     * Logs to the spies.
     *
     * @return void
     */
    protected function setUp(): void
    {
        global $logger, $logStream;

        $this->logStream = new LogStreamSpy();
        $logStream       = $this->logStream;
        $logged          = &$this->logged;
        $logger          = new class ($logged) {
            /**
             * Keeps the messages in the test's list.
             *
             * @param array $logged The test's list.
             */
            public function __construct(private array &$logged)
            {
            }


            /**
             * Takes a message the way the legacy logger does.
             *
             * @param string $message The message.
             *
             * @return void
             */
            public function log(string $message): void
            {
                $this->logged[] = $message;

            }//end log()
        };

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
        handleResponse($this->forbidden(), "GET", "application/vnd.github+json", [403]);

        $this->assertSame([], $this->logged);
        $this->assertSame(
            ["debug: GitHub API GET request returned an expected HTTP 403: "
                . "Invalid GitHub response. HTTP Status Code: 403 - Upgrade to GitHub Pro to enable this feature."],
            $this->logStream->lines()
        );

    }//end testAnExpectedStatusIsOnlyDebugged()


    /**
     * A 403 the caller doesn't expect is still logged as an error, to both loggers.
     *
     * @return void
     */
    public function testAnUnexpectedStatusIsAnError(): void
    {
        handleResponse($this->forbidden(), "GET", "application/vnd.github+json");

        $this->assertSame(
            ["Invalid GitHub response. HTTP Status Code: 403 - Upgrade to GitHub Pro to enable this feature."],
            $this->logged
        );
        $this->assertStringStartsWith(
            "error: GitHub API GET request failed with HTTP 403",
            $this->logStream->lines()[0]
        );

    }//end testAnUnexpectedStatusIsAnError()


    /**
     * The validation errors and the documentation link GitHub sends are part of the logged message.
     *
     * @return void
     */
    public function testTheErrorMessageDescribesGitHubsAnswer(): void
    {
        $body = json_encode(
            [
                "message"           => "Validation Failed",
                "errors"            => [
                    ["resource" => "Issue", "field" => "title", "code" => "missing_field"],
                    "plain",
                ],
                "documentation_url" => "https://docs.github.com/rest",
            ]
        );

        handleResponse(new Response(false, $body, "", 422, "url", null), "POST", "application/vnd.github+json");

        $this->assertSame(
            ["Invalid GitHub response. HTTP Status Code: 422 - Validation Failed\nErrors:"
                . "\n- [Issue] title: missing_field\n- plain\n📚 See: https://docs.github.com/rest"],
            $this->logged
        );

    }//end testTheErrorMessageDescribesGitHubsAnswer()


    /**
     * GitHub's answer when rulesets aren't on the repository's plan.
     *
     * @return Response
     */
    private function forbidden(): Response
    {
        return new Response(false, self::NOT_ON_PLAN, "", 403, "url", null);

    }//end forbidden()


}//end class
