<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A job that piggybacks on an existing worker (ProcessingManager): after
 * each batch of queued items the worker asks every registered ScheduledJob
 * to run if its schedule says so. The schedule is consulted at most once
 * per `checkIntervalSeconds`, so a daemon looping every 100 ms doesn't
 * query the database for the schedule on every pass, while a cron/HTTP
 * invocation (one process a minute) checks right away on its first batch.
 *
 * The run is claimed on the schedule *before* the job runs: a job that
 * keeps failing is retried at its own cadence, not on every check, and
 * two workers checking at the same moment can't both run it.
 */
final class ScheduledJob
{
    private string $name;
    private ScheduleInterface $schedule;
    private Closure $job;
    private int $checkIntervalSeconds;
    private int $nextCheckAt = 0;

    /**
     * @param string            $name                 Job name, for logs.
     * @param ScheduleInterface $schedule             When the job is due.
     * @param Closure           $job                  The job itself; its return value is ignored.
     * @param int               $checkIntervalSeconds How often the schedule may be consulted.
     */
    public function __construct(string $name, ScheduleInterface $schedule, Closure $job, int $checkIntervalSeconds = 60)
    {
        if (trim($name) === "") {
            throw new InvalidArgumentException("The job name cannot be empty");
        }
        if ($checkIntervalSeconds < 1) {
            throw new InvalidArgumentException("The check interval must be at least 1 second, {$checkIntervalSeconds} given");
        }

        $this->name = $name;
        $this->schedule = $schedule;
        $this->job = $job;
        $this->checkIntervalSeconds = $checkIntervalSeconds;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function describe(): string
    {
        return "{$this->name} ({$this->schedule->describe()})";
    }

    /**
     * Runs the job when its schedule hands this check the run. Exceptions
     * from the job propagate to the caller (the worker logs them); the run
     * has already been claimed by then.
     *
     * @return bool True when the job ran (or threw).
     */
    public function runIfDue(DateTimeImmutable $now): bool
    {
        if ($now->getTimestamp() < $this->nextCheckAt) {
            return false;
        }

        $this->nextCheckAt = $now->getTimestamp() + $this->checkIntervalSeconds;

        if (!$this->schedule->claim($now)) {
            return false;
        }

        echo "[" . $now->format("Y-m-d H:i:s") . "] Scheduled job '{$this->name}' starting.\n";
        $startedAt = microtime(true);

        try {
            ($this->job)();
        } finally {
            $elapsed = round(microtime(true) - $startedAt, 1);
            echo "[" . date("Y-m-d H:i:s") . "] Scheduled job '{$this->name}' finished in {$elapsed}s.\n";
        }

        return true;
    }
}
