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
        $trace = new \stdClass();
        $trace->secondsSinceLastRun = 0;
        $schedule = new IntervalSchedule(3600, static fn(): ?int => $trace->secondsSinceLastRun);

        $trace->secondsSinceLastRun = 10;
        $this->assertFalse($schedule->isDue($this->at("2026-10-20 10:00:10")));

        $trace->secondsSinceLastRun = 3599;
        $this->assertFalse($schedule->isDue($this->at("2026-10-20 10:59:59")));

        $trace->secondsSinceLastRun = 3600;
        $this->assertTrue($schedule->isDue($this->at("2026-10-20 11:00:00")));

        $trace->secondsSinceLastRun = 7200;
        $this->assertTrue($schedule->isDue($this->at("2026-10-20 12:00:00")));
    }

    public function testARunThatLeftNoTraceStillHoldsTheIntervalWithinTheProcess(): void
    {
        // The trace never moves (nothing to sync), but the job just ran in this process.
        $schedule = new IntervalSchedule(3600, static fn(): ?int => null);
        $this->assertTrue($schedule->claim($this->at("2026-10-20 10:00:00")));

        $this->assertFalse($schedule->isDue($this->at("2026-10-20 10:30:00")));
        $this->assertFalse($schedule->claim($this->at("2026-10-20 10:30:00")));
        $this->assertTrue($schedule->isDue($this->at("2026-10-20 11:00:00")));
        $this->assertTrue($schedule->claim($this->at("2026-10-20 11:00:00")));
    }

    public function testClaimIsRefusedWhileTheTraceIsRecent(): void
    {
        $schedule = new IntervalSchedule(3600, static fn(): ?int => 120);

        $this->assertFalse($schedule->claim($this->at("2026-10-20 10:00:00")));
    }

    public function testTheTraceIsNotConsultedWhileTheProcessHoldsTheInterval(): void
    {
        $trace = new \stdClass();
        $trace->calls = 0;
        $schedule = new IntervalSchedule(3600, static function () use ($trace): ?int {
            $trace->calls++;
            return null;
        });

        $schedule->isDue($this->at("2026-10-20 10:00:00"));
        $this->assertSame(1, $trace->calls);

        $schedule->claim($this->at("2026-10-20 10:00:00"));
        $this->assertSame(2, $trace->calls);

        $schedule->isDue($this->at("2026-10-20 10:30:00"));
        $this->assertSame(2, $trace->calls);
    }

    public function testRejectsANonPositiveInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("at least 1 second");

        $this->assertInstanceOf(IntervalSchedule::class, new IntervalSchedule(0, static fn(): ?int => null));
    }

    public function testDescribesItself(): void
    {
        $this->assertSame("every 3600 seconds", (new IntervalSchedule(3600, static fn(): ?int => null))->describe());
    }
}
