<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

/**
 * Sync state kept in memory only — for tests and for dry runs that must
 * not write anything.
 */
class ArraySyncStateStore implements SyncStateStoreInterface
{
    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string> $values Initial contents.
     */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }
}
