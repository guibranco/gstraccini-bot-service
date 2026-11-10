<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Sends a class's log entries to LogStream (the global `$logStream`, which is
 * null when LogStream is disabled or not configured) under the category the
 * class names in its LOG_CHANNEL constant.
 */
trait LogsToStream
{


    /**
     * Sends one entry to LogStream.
     *
     * @param string $level   debug, info, notice, warning, error or critical.
     * @param string $message The message template; its placeholders are keys of the context.
     * @param array  $context The values of the placeholders, plus any other detail worth keeping.
     *
     * @return void
     */
    private function logToStream(string $level, string $message, array $context): void
    {
        global $logStream;

        $logStream?->{$level}($message, $context, static::LOG_CHANNEL);

    }//end logToStream()


}//end trait
