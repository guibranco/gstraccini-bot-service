<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

/**
 * Runs the job once a calendar month, from a given day of the month (the
 * 1st by default) on: due the first time the worker checks on or after that
 * day in a month the job hasn't run in yet. A day beyond the month's length
 * (e.g. 31 in April) means the month's last day.
 *
 * "On or after" rather than "on", so a worker that wasn't running on the
 * day catches up as soon as it is back, instead of skipping the month.
 *
 * The last run is persisted through a SyncStateStoreInterface (the shared
 * database's `settings` table in production), because the workers run as a
 * fresh process every minute in cron/HTTP mode and restart on every deploy
 * in daemon mode; kept in memory, the job would run on every one of those
 * starts. The stored value is the run's date and time (ISO 8601, with its
 * offset); only its calendar month matters here.
 */
class MonthlySchedule implements ScheduleInterface
{
    private SyncStateStoreInterface $store;
    private string $stateKey;
    private int $dayOfMonth;

    /**
     * @param SyncStateStoreInterface $store      Where the last run is kept.
     * @param string                  $stateKey   The store key for this job's last run.
     * @param int                     $dayOfMonth Day of the month the job becomes due (1-31).
     */
    public function __construct(SyncStateStoreInterface $store, string $stateKey, int $dayOfMonth = 1)
    {
        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            throw new InvalidArgumentException("The day of month must be between 1 and 31, {$dayOfMonth} given");
        }

        $this->store = $store;
        $this->stateKey = $stateKey;
        $this->dayOfMonth = $dayOfMonth;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        $daysInMonth = (int) $now->format("t");
        $runDay = min($this->dayOfMonth, $daysInMonth);

        if ((int) $now->format("j") < $runDay) {
            return false;
        }

        $lastRun = $this->lastRun($now);

        return $lastRun === null || $lastRun->format("Y-m") !== $now->format("Y-m");
    }

    public function markRun(DateTimeImmutable $now): void
    {
        $this->store->set($this->stateKey, $now->format(DATE_ATOM));
    }

    public function describe(): string
    {
        return "monthly, from day {$this->dayOfMonth}";
    }

    /**
     * The last run, in $now's time zone, or null when the job never ran or
     * the stored value can't be read (treated as never: one extra run beats
     * never running again).
     */
    private function lastRun(DateTimeImmutable $now): ?DateTimeImmutable
    {
        $stored = $this->store->get($this->stateKey);
        if ($stored === null || trim($stored) === "") {
            return null;
        }

        try {
            return (new DateTimeImmutable($stored))->setTimezone($now->getTimezone());
        } catch (Exception) {
            return null;
        }
    }
}
