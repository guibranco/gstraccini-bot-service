<?php

namespace GuiBranco\GStracciniBot\Tests\Handlers;

/**
 * Stands for the log stream the handlers log through, and keeps what it
 * received, at every level.
 */
class LogStreamSpy
{
    /** The category the pull requests handler logs under. */
    public const PULL_REQUESTS = "pull_requests";

    /**
     * The entries received, in order, each with its `level`.
     *
     * @var array
     */
    private array $entries = [];


    /**
     * Takes a debug entry the way LogStream::debug() does.
     *
     * @param string      $message  What is logged.
     * @param array       $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function debug(string $message, array $context, string $category, ?string $traceId): void
    {
        $this->record("debug", $message, $context, $category, $traceId);

    }//end debug()


    /**
     * Takes an info entry the way LogStream::info() does.
     *
     * @param string      $message  What is logged.
     * @param array       $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function info(string $message, array $context, string $category, ?string $traceId): void
    {
        $this->record("info", $message, $context, $category, $traceId);

    }//end info()


    /**
     * Takes a warning the way LogStream::warning() does.
     *
     * @param string      $message  What is warned about.
     * @param array       $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function warning(string $message, array $context, string $category, ?string $traceId): void
    {
        $this->record("warning", $message, $context, $category, $traceId);

    }//end warning()


    /**
     * Takes an error the way LogStream::error() does.
     *
     * @param string      $message  What failed.
     * @param array       $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function error(string $message, array $context, string $category, ?string $traceId): void
    {
        $this->record("error", $message, $context, $category, $traceId);

    }//end error()


    /**
     * The warnings received, in order.
     *
     * @return array Each one with its `message`, `context`, `category` and `traceId`.
     */
    public function warnings(): array
    {
        return $this->entries("warning");

    }//end warnings()


    /**
     * The entries received at a level, in order.
     *
     * @param string $level The level: debug, info, warning or error.
     *
     * @return array Each one with its `message`, `context`, `category` and `traceId`.
     */
    public function entries(string $level): array
    {
        $entries = [];
        foreach ($this->entries as $entry) {
            if ($entry["level"] === $level) {
                unset($entry["level"]);
                $entries[] = $entry;
            }
        }

        return $entries;

    }//end entries()


    /**
     * Keeps an entry.
     *
     * @param string      $level    The level it was logged at.
     * @param string      $message  What is logged.
     * @param array       $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    private function record(string $level, string $message, array $context, string $category, ?string $traceId): void
    {
        $this->entries[] = compact("level", "message", "context", "category", "traceId");

    }//end record()


}//end class
