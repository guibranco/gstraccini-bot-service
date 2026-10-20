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
     * Whether the job should run now. A look only: nothing is recorded.
     */
    public function isDue(DateTimeImmutable $now): bool;

    /**
     * Takes the run that is due at $now, recording it so that no other
     * check — in this process or another one running the same worker —
     * takes the same run. Returns false when nothing is due or another
     * check took it first; the job runs only on true.
     */
    public function claim(DateTimeImmutable $now): bool;

    /**
     * Human-readable description of the cadence, for logs.
     */
    public function describe(): string;
}
