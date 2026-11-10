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
     * @param string $message      The message template.
     * @param mixed  ...$arguments The context, the category and the trace id, as LogStream takes them.
     *
     * @return void
     */
    public function debug(string $message, mixed ...$arguments): void
    {
        $this->record("debug", $message, $arguments);

    }//end debug()


    /**
     * Takes an info entry the way LogStream::info() does.
     *
     * @param string $message      The message template.
     * @param mixed  ...$arguments The context, the category and the trace id, as LogStream takes them.
     *
     * @return void
     */
    public function info(string $message, mixed ...$arguments): void
    {
        $this->record("info", $message, $arguments);

    }//end info()


    /**
     * Takes a notice entry the way LogStream::notice() does.
     *
     * @param string $message      The message template.
     * @param mixed  ...$arguments The context, the category and the trace id, as LogStream takes them.
     *
     * @return void
     */
    public function notice(string $message, mixed ...$arguments): void
    {
        $this->record("notice", $message, $arguments);

    }//end notice()


    /**
     * Takes a warning the way LogStream::warning() does.
     *
     * @param string $message      What is warned about.
     * @param mixed  ...$arguments The context, the category and the trace id, as LogStream takes them.
     *
     * @return void
     */
    public function warning(string $message, mixed ...$arguments): void
    {
        $this->record("warning", $message, $arguments);

    }//end warning()


    /**
     * Takes an error entry the way LogStream::error() does.
     *
     * @param string $message      The message template.
     * @param mixed  ...$arguments The context, the category and the trace id, as LogStream takes them.
     *
     * @return void
     */
    public function error(string $message, mixed ...$arguments): void
    {
        $this->record("error", $message, $arguments);

    }//end error()


    /**
     * Takes a critical entry the way LogStream::critical() does.
     *
     * @param string $message      The message template.
     * @param mixed  ...$arguments The context, the category and the trace id, as LogStream takes them.
     *
     * @return void
     */
    public function critical(string $message, mixed ...$arguments): void
    {
        $this->record("critical", $message, $arguments);

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
                    $replacements[sprintf("{%s}", $key)] = (string) $value;
                }
            }

            $lines[] = sprintf("%s: %s", $entry["level"], strtr($entry["message"], $replacements));
        }

        return $lines;

    }//end lines()


    /**
     * Keeps an entry.
     *
     * @param string $level     The level it was sent at.
     * @param string $message   The message template.
     * @param array  $arguments The context, the category and the trace id, when given.
     *
     * @return void
     */
    private function record(string $level, string $message, array $arguments): void
    {
        $context = ($arguments[0] ?? []);
        $category = ($arguments[1] ?? "");
        $traceId = ($arguments[2] ?? null);
        $this->entries[] = compact("level", "message", "context", "category", "traceId");

    }//end record()


}//end class
