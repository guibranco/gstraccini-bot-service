<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

use DateTimeImmutable;

/**
 * Decides when a ScheduledJob, checked from a worker's loop
 * (ProcessingManager::addScheduledJob()), should run. The clock is passed
 * in, so the rules are unit-tested without sleeping; the last run is kept
 * wherever the implementation needs it to survive a process restart.
 */
interface ScheduleInterface
{
    /**
     * Whether the job should run now.
     */
    public function isDue(DateTimeImmutable $now): bool;

    /**
     * Records that the job ran (or was started) at $now, so `isDue()` moves
     * on to the next occurrence.
     */
    public function markRun(DateTimeImmutable $now): void;

    /**
     * Human-readable description of the cadence, for logs.
     */
    public function describe(): string;
}
