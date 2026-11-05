<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Runs the job once every N seconds, counted from the last run.
 *
 * The last run is read from the job's own trace in the database through
 * the provider passed in (for the repositories sync: seconds since the
 * newest `github_repositories.LastSyncDate`, i.e. since the last claim), so
 * the cadence survives process restarts and is shared by every process
 * running the worker. The database computes the elapsed seconds, which
 * keeps the PHP and database clocks' time zones out of the comparison.
 *
 * The in-process last run is tracked as well: when a run leaves no trace
 * (nothing to sync), the provider still reports the older trace and the
 * job would otherwise be due again on the very next check.
 *
 * The claim here is in-process only: the job's own work is what leaves
 * the trace (the repositories sync claims its repository row with a
 * conditional update), so two workers taking the same tick at most each
 * sync a different repository in that hour.
 */
class IntervalSchedule implements ScheduleInterface
{
    private int $intervalSeconds;
    /** @var callable(): ?int */
    private $secondsSinceLastRunProvider;
    private ?DateTimeImmutable $lastRunInProcess = null;

    /**
     * @param int               $intervalSeconds             Seconds between runs.
     * @param callable(): ?int  $secondsSinceLastRunProvider Returns the seconds elapsed since the job's last persisted
     *                                                       trace, or null when there is none yet.
     */
    public function __construct(int $intervalSeconds, callable $secondsSinceLastRunProvider)
    {
        if ($intervalSeconds < 1) {
            throw new InvalidArgumentException("The interval must be at least 1 second, {$intervalSeconds} given");
        }

        $this->intervalSeconds = $intervalSeconds;
        $this->secondsSinceLastRunProvider = $secondsSinceLastRunProvider;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        if ($this->lastRunInProcess !== null
            && $now->getTimestamp() - $this->lastRunInProcess->getTimestamp() < $this->intervalSeconds) {
            return false;
        }

        $secondsSinceLastRun = ($this->secondsSinceLastRunProvider)();

        return $secondsSinceLastRun === null || $secondsSinceLastRun >= $this->intervalSeconds;
    }

    public function claim(DateTimeImmutable $now): bool
    {
        if (!$this->isDue($now)) {
            return false;
        }

        $this->lastRunInProcess = $now;

        return true;
    }

    public function describe(): string
    {
        return "every {$this->intervalSeconds} seconds";
    }
}
