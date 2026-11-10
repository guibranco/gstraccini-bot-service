<?php

namespace GuiBranco\GStracciniBot\Library;

use Throwable;

/**
 * The context of a LogStream entry. Its keys are the placeholders of the
 * entry's message template, which LogStream renders when the entry is
 * stored: `new LogContext(repo: "owner/name", pr: 42)` goes with
 * "Processing pull request {repo}#{pr}".
 */
class LogContext
{
    /** The key of the message of an exception. */
    public const ERROR = "error";

    /**
     * The values of the context, by key.
     *
     * @var array
     */
    private array $values;


    /**
     * Takes the values of the context, each as an argument named after its key.
     *
     * @param mixed ...$values The values, by key.
     */
    public function __construct(mixed ...$values)
    {
        $this->values = $values;

    }//end __construct()


    /**
     * Adds the message of an exception as `error`, and where it was thrown as
     * `file` and `line`.
     *
     * @param Throwable $exception The exception to log.
     *
     * @return LogContext This context.
     */
    public function withError(Throwable $exception): LogContext
    {
        $this->values[self::ERROR] = $exception->getMessage();
        $this->values["file"] = $exception->getFile();
        $this->values["line"] = $exception->getLine();

        return $this;

    }//end withError()


    /**
     * The context as LogStream takes it.
     *
     * @return array The values, by key.
     */
    public function toArray(): array
    {
        return $this->values;

    }//end toArray()


}//end class
