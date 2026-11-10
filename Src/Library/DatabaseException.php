<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Thrown when a MySQL connection or statement fails, so the worker's item error
 * handling can report it (instead of the process exiting silently).
 */
class DatabaseException extends \RuntimeException
{
}
