<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\LogContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The context that goes with a LogStream message template.
 */
class LogContextTest extends TestCase
{
    /** A message template, as the handlers log them. */
    private const TEMPLATE = "PR {repo}#{pr} has conflicts";


    /**
     * The named arguments become the keys of the context, in order.
     *
     * @return void
     */
    public function testKeysAreTheNamesOfTheArguments(): void
    {
        $context = new LogContext(repo: "owner/repo", pr: 42, sender: null);

        $this->assertSame(["repo", "pr", "sender"], array_keys($context->toArray()));
        $this->assertSame("owner/repo", $context->toArray()["repo"]);
        $this->assertSame(42, $context->toArray()["pr"]);
        $this->assertNull($context->toArray()["sender"]);

    }//end testKeysAreTheNamesOfTheArguments()


    /**
     * An array of an existing context can be spread into a new one.
     *
     * @return void
     */
    public function testAnExistingContextCanBeSpread(): void
    {
        $existing = ["entity" => "pull_requests"];
        $existing["sequence"] = 7;
        $context = new LogContext(...$existing);

        $this->assertSame($existing, $context->toArray());

    }//end testAnExistingContextCanBeSpread()


    /**
     * An exception adds its message as `error`, and its file and line.
     *
     * @return void
     */
    public function testAnErrorAddsItsMessageFileAndLine(): void
    {
        $exception = new RuntimeException("Not Found");
        $context = (new LogContext(repo: "owner/repo"))->withError($exception)->toArray();

        $this->assertSame("owner/repo", $context["repo"]);
        $this->assertSame("Not Found", $context[LogContext::ERROR]);
        $this->assertSame(__FILE__, $context["file"]);
        $this->assertSame($exception->getLine(), $context["line"]);

    }//end testAnErrorAddsItsMessageFileAndLine()


    /**
     * Every placeholder of a template is a key of the context built for it,
     * so LogStream can render the message.
     *
     * @return void
     */
    public function testEveryPlaceholderIsAContextKey(): void
    {
        $context = (new LogContext(repo: "owner/repo", pr: 350))->toArray();
        preg_match_all("/\\{([A-Za-z_][A-Za-z0-9_.]*)\\}/", self::TEMPLATE, $matches);

        $this->assertSame(["repo", "pr"], $matches[1]);
        foreach ($matches[1] as $placeholder) {
            $this->assertArrayHasKey($placeholder, $context);
        }

    }//end testEveryPlaceholderIsAContextKey()


}//end class
