<?php

namespace GuiBranco\GStracciniBot\Tests\Library\Sync;

use DateTimeImmutable;
use GuiBranco\GStracciniBot\Library\Sync\IntervalSchedule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IntervalScheduleTest extends TestCase
{
    private function at(string $dateTime): DateTimeImmutable
    {
        return new DateTimeImmutable($dateTime);
    }

    public function testIsDueWhenThereIsNoTraceOfAPreviousRun(): void
    {
        $schedule = new IntervalSchedule(3600, static fn(): ?int => null);

        $this->assertTrue($schedule->isDue($this->at("2026-10-20 10:00:00")));
    }

    public function testFollowsThePersistedTrace(): void
    {
        $secondsSinceLastRun = 0;
        $schedule = new IntervalSchedule(3600, static function () use (&$secondsSinceLastRun): ?int {
            return $secondsSinceLastRun;
        });

        $secondsSinceLastRun = 10;
        $this->assertFalse($schedule->isDue($this->at("2026-10-20 10:00:10")));

        $secondsSinceLastRun = 3599;
        $this->assertFalse($schedule->isDue($this->at("2026-10-20 10:59:59")));

        $secondsSinceLastRun = 3600;
        $this->assertTrue($schedule->isDue($this->at("2026-10-20 11:00:00")));

        $secondsSinceLastRun = 7200;
        $this->assertTrue($schedule->isDue($this->at("2026-10-20 12:00:00")));
    }

    public function testARunThatLeftNoTraceStillHoldsTheIntervalWithinTheProcess(): void
    {
        // The trace never moves (nothing to sync), but the job just ran in this process.
        $schedule = new IntervalSchedule(3600, static fn(): ?int => null);
        $schedule->markRun($this->at("2026-10-20 10:00:00"));

        $this->assertFalse($schedule->isDue($this->at("2026-10-20 10:30:00")));
        $this->assertTrue($schedule->isDue($this->at("2026-10-20 11:00:00")));
    }

    public function testTheTraceIsConsultedOnlyOncePerCheckAndNotWhileTheProcessHoldsTheInterval(): void
    {
        $calls = 0;
        $schedule = new IntervalSchedule(3600, static function () use (&$calls): ?int {
            $calls++;
            return null;
        });

        $schedule->isDue($this->at("2026-10-20 10:00:00"));
        $this->assertSame(1, $calls);

        $schedule->markRun($this->at("2026-10-20 10:00:00"));
        $schedule->isDue($this->at("2026-10-20 10:30:00"));
        $this->assertSame(1, $calls);
    }

    public function testRejectsANonPositiveInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IntervalSchedule(0, static fn(): ?int => null);
    }

    public function testDescribesItself(): void
    {
        $this->assertSame("every 3600 seconds", (new IntervalSchedule(3600, static fn(): ?int => null))->describe());
    }
}
