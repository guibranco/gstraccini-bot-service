<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

use DateTimeImmutable;
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
 * offset); only its calendar month matters here. A run is taken with the
 * store's compare-and-set, so two workers checking at the same moment
 * can't both take it.
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
        return $this->isDayReached($now) && $this->isDueGivenStoredRun($this->store->get($this->stateKey), $now);
    }

    public function claim(DateTimeImmutable $now): bool
    {
        if (!$this->isDayReached($now)) {
            return false;
        }

        $stored = $this->store->get($this->stateKey);
        if (!$this->isDueGivenStoredRun($stored, $now)) {
            return false;
        }

        // Only one of the workers that read the same stored value gets to replace it.
        return $this->store->compareAndSet($this->stateKey, $stored, $now->format(DATE_ATOM));
    }

    public function describe(): string
    {
        return "monthly, from day {$this->dayOfMonth}";
    }

    private function isDayReached(DateTimeImmutable $now): bool
    {
        $daysInMonth = (int) $now->format("t");
        $runDay = min($this->dayOfMonth, $daysInMonth);

        return (int) $now->format("j") >= $runDay;
    }

    /**
     * Due unless the stored run falls in $now's calendar month. A stored
     * value that isn't the ISO 8601 date claim() writes counts as never run
     * (one extra run beats never running again).
     */
    private function isDueGivenStoredRun(?string $stored, DateTimeImmutable $now): bool
    {
        if ($stored === null || trim($stored) === "") {
            return true;
        }

        $lastRun = DateTimeImmutable::createFromFormat(DATE_ATOM, trim($stored));
        if ($lastRun === false) {
            return true;
        }

        return $lastRun->setTimezone($now->getTimezone())->format("Y-m") !== $now->format("Y-m");
    }
}
