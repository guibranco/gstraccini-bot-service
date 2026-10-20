<?php

namespace GuiBranco\GStracciniBot\Tests\Library\Sync;

use DateTimeImmutable;
use DateTimeZone;
use GuiBranco\GStracciniBot\Library\Sync\ArraySyncStateStore;
use GuiBranco\GStracciniBot\Library\Sync\MonthlySchedule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MonthlyScheduleTest extends TestCase
{
    private const KEY = "sync-installations.last-run";

    private function at(string $dateTime): DateTimeImmutable
    {
        return new DateTimeImmutable($dateTime, new DateTimeZone("Europe/Dublin"));
    }

    public function testIsDueOnTheFirstOfTheMonthWhenItNeverRan(): void
    {
        $schedule = new MonthlySchedule(new ArraySyncStateStore(), self::KEY);

        $this->assertTrue($schedule->isDue($this->at("2026-11-01 00:00:30")));
        $this->assertTrue($schedule->isDue($this->at("2026-11-01 23:59:00")));
    }

    public function testCatchesUpLaterInTheMonthWhenItDidNotRunOnTheDay(): void
    {
        $store = new ArraySyncStateStore([self::KEY => "2026-10-01T00:01:00+01:00"]);
        $schedule = new MonthlySchedule($store, self::KEY);

        $this->assertTrue($schedule->isDue($this->at("2026-11-20 10:00:00")));
    }

    public function testIsNotDueAgainInTheMonthItAlreadyRan(): void
    {
        $store = new ArraySyncStateStore([self::KEY => "2026-11-01T00:01:00+00:00"]);
        $schedule = new MonthlySchedule($store, self::KEY);

        $this->assertFalse($schedule->isDue($this->at("2026-11-01 00:02:00")));
        $this->assertFalse($schedule->isDue($this->at("2026-11-20 18:00:00")));
        $this->assertTrue($schedule->isDue($this->at("2026-12-01 00:00:00")));
    }

    public function testMarkRunPersistsTheRunAndBlocksTheRestOfTheMonth(): void
    {
        $store = new ArraySyncStateStore();
        $schedule = new MonthlySchedule($store, self::KEY);

        $run = $this->at("2026-11-01 00:01:00");
        $this->assertTrue($schedule->isDue($run));
        $schedule->markRun($run);

        $this->assertSame("2026-11-01T00:01:00+00:00", $store->get(self::KEY));
        $this->assertFalse($schedule->isDue($this->at("2026-11-01 00:02:00")));
        $this->assertFalse((new MonthlySchedule($store, self::KEY))->isDue($this->at("2026-11-15 12:00:00")));
        $this->assertTrue((new MonthlySchedule($store, self::KEY))->isDue($this->at("2026-12-01 00:00:00")));
    }

    public function testComparesTheRunsMonthInTheCheckersTimeZone(): void
    {
        // Stored with another offset: 2026-10-31T23:30:00-05:00 is 2026-11-01 04:30 in Dublin,
        // so as far as the Dublin-based worker is concerned the November run already happened.
        $store = new ArraySyncStateStore([self::KEY => "2026-10-31T23:30:00-05:00"]);
        $schedule = new MonthlySchedule($store, self::KEY);

        $this->assertFalse($schedule->isDue($this->at("2026-11-01 10:00:00")));
    }

    public function testAnUnreadableStoredValueCountsAsNeverRan(): void
    {
        $store = new ArraySyncStateStore([self::KEY => "not a date"]);
        $schedule = new MonthlySchedule($store, self::KEY);

        $this->assertTrue($schedule->isDue($this->at("2026-11-01 00:00:00")));
    }

    public function testIsNeverDueBeforeTheDay(): void
    {
        $schedule = new MonthlySchedule(new ArraySyncStateStore(), self::KEY, 15);

        $this->assertFalse($schedule->isDue($this->at("2026-11-01 12:00:00")));
        $this->assertFalse($schedule->isDue($this->at("2026-11-14 23:59:59")));
        $this->assertTrue($schedule->isDue($this->at("2026-11-15 00:00:00")));
        $this->assertTrue($schedule->isDue($this->at("2026-11-16 12:00:00")));
    }

    public function testADayBeyondTheMonthsLengthMeansItsLastDay(): void
    {
        $schedule = new MonthlySchedule(new ArraySyncStateStore(), self::KEY, 31);

        $this->assertFalse($schedule->isDue($this->at("2026-04-29 12:00:00")));
        $this->assertTrue($schedule->isDue($this->at("2026-04-30 12:00:00")));
        $this->assertFalse($schedule->isDue($this->at("2026-05-30 12:00:00")));
        $this->assertTrue($schedule->isDue($this->at("2026-05-31 12:00:00")));
    }

    public function testRejectsAnInvalidDayOfMonth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonthlySchedule(new ArraySyncStateStore(), self::KEY, 0);
    }

    public function testDescribesItself(): void
    {
        $this->assertSame("monthly, from day 1", (new MonthlySchedule(new ArraySyncStateStore(), self::KEY))->describe());
    }
}
