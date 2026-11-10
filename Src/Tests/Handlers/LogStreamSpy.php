<?php

namespace GuiBranco\GStracciniBot\Tests\Handlers;

/**
 * Stands for the log stream the handlers log through, and keeps what it
 * was sent, at every level.
 */
class LogStreamSpy
{
    /** The category the pull requests handler logs under. */
    public const PULL_REQUESTS = "pull_requests";

    /**
     * The entries received, in order.
     *
     * @var array
     */
    private array $entries = [];


    /**
     * Takes a debug entry the way LogStream::debug() does.
     *
     * @param string      $message  The message template.
     * @param array|null  $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function debug(string $message, ?array $context = null, string $category = "", ?string $traceId = null): void
    {
        $this->record("debug", $message, $context, $category, $traceId);

    }//end debug()


    /**
     * Takes an info entry the way LogStream::info() does.
     *
     * @param string      $message  The message template.
     * @param array|null  $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function info(string $message, ?array $context = null, string $category = "", ?string $traceId = null): void
    {
        $this->record("info", $message, $context, $category, $traceId);

    }//end info()


    /**
     * Takes a notice entry the way LogStream::notice() does.
     *
     * @param string      $message  The message template.
     * @param array|null  $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function notice(string $message, ?array $context = null, string $category = "", ?string $traceId = null): void
    {
        $this->record("notice", $message, $context, $category, $traceId);

    }//end notice()


    /**
     * Takes a warning the way LogStream::warning() does.
     *
     * @param string      $message  What is warned about.
     * @param array|null  $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function warning(string $message, ?array $context = null, string $category = "", ?string $traceId = null): void
    {
        $this->record("warning", $message, $context, $category, $traceId);

    }//end warning()


    /**
     * Takes an error entry the way LogStream::error() does.
     *
     * @param string      $message  The message template.
     * @param array|null  $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function error(string $message, ?array $context = null, string $category = "", ?string $traceId = null): void
    {
        $this->record("error", $message, $context, $category, $traceId);

    }//end error()


    /**
     * Takes a critical entry the way LogStream::critical() does.
     *
     * @param string      $message  The message template.
     * @param array|null  $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    public function critical(string $message, ?array $context = null, string $category = "", ?string $traceId = null): void
    {
        $this->record("critical", $message, $context, $category, $traceId);

    }//end critical()


    /**
     * The warnings received, in order.
     *
     * @return array Each one with its `message`, `context`, `category` and `traceId`.
     */
    public function warnings(): array
    {
        $warnings = [];
        foreach ($this->entries as $entry) {
            if ($entry["level"] === "warning") {
                unset($entry["level"]);
                $warnings[] = $entry;
            }
        }

        return $warnings;

    }//end warnings()


    /**
     * The entries received, in order, each as its level and its message with
     * the placeholders filled in from its context, the way LogStream shows it:
     * "warning: PR owner/repo#350 has conflicts".
     *
     * @return array<int, string> The rendered entries.
     */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->entries as $entry) {
            $replacements = [];
            foreach ($entry["context"] as $key => $value) {
                if (is_scalar($value) === true) {
                    $replacements["{" . $key . "}"] = (string) $value;
                }
            }

            $lines[] = $entry["level"] . ": " . strtr($entry["message"], $replacements);
        }

        return $lines;

    }//end lines()


    /**
     * Keeps an entry.
     *
     * @param string      $level    The level it was sent at.
     * @param string      $message  The message template.
     * @param array|null  $context  The details that go with it.
     * @param string      $category The category it is logged under.
     * @param string|null $traceId  The delivery it belongs to.
     *
     * @return void
     */
    private function record(string $level, string $message, ?array $context, string $category, ?string $traceId): void
    {
        $context = ($context ?? []);
        $this->entries[] = compact("level", "message", "context", "category", "traceId");

    }//end record()


}//end class
