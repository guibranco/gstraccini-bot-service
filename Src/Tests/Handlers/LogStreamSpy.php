<?php

namespace GuiBranco\GStracciniBot\Tests\Handlers;

/**
 * Stands for the log stream the handlers warn through, and keeps what it
 * was warned about.
 */
class LogStreamSpy
{
    /** The category the pull requests handler logs under. */
    public const PULL_REQUESTS = "pull_requests";

    /**
     * The warnings received, in order.
     *
     * @var array
     */
    private array $warnings = [];


    /**
     * Takes a warning the way LogStream::warning() does.
     *
     * @param string $message  What is warned about.
     * @param array  $context  The details that go with it.
     * @param string $category The category it is logged under.
     * @param string $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function warning(string $message, array $context, string $category, string $traceId): void
    {
        $this->warnings[] = compact("message", "context", "category", "traceId");

    }//end warning()


    /**
     * The warnings received, in order.
     *
     * @return array Each one with its `message`, `context`, `category` and `traceId`.
     */
    public function warnings(): array
    {
        return $this->warnings;

    }//end warnings()


}//end class
