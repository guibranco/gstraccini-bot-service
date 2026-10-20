<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

/**
 * Key/value storage for the small amount of state the scheduled syncs keep
 * between runs (e.g. when the monthly installations sync last ran). It has
 * to outlive the PHP process: the workers run as a fresh process every
 * minute in cron/HTTP mode and get restarted on every deploy in daemon
 * mode, so in-memory state alone would re-run a monthly job every minute.
 */
interface SyncStateStoreInterface
{
    /**
     * @return string|null The stored value, or null when the key was never set.
     */
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;
}
