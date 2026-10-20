<?php

namespace GuiBranco\GStracciniBot\Tests\Library\Sync;

use DateTimeImmutable;
use GuiBranco\GStracciniBot\Library\Sync\ScheduledJob;
use GuiBranco\GStracciniBot\Library\Sync\ScheduleInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ScheduledJobTest extends TestCase
{
    private function at(string $dateTime): DateTimeImmutable
    {
        return new DateTimeImmutable($dateTime);
    }

    /**
     * A schedule that is due whenever told so, and records its markRun() calls.
     */
    private function schedule(bool &$due, array &$markedRuns): ScheduleInterface
    {
        return new class ($due, $markedRuns) implements ScheduleInterface {
            private bool $due;
            private array $markedRuns;

            public function __construct(bool &$due, array &$markedRuns)
            {
                $this->due = &$due;
                $this->markedRuns = &$markedRuns;
            }

            public function isDue(DateTimeImmutable $now): bool
            {
                return $this->due;
            }

            public function markRun(DateTimeImmutable $now): void
            {
                $this->markedRuns[] = $now->format("H:i:s");
            }

            public function describe(): string
            {
                return "test schedule";
            }
        };
    }

    public function testRunsTheJobWhenDueAndMarksTheScheduleFirst(): void
    {
        $due = true;
        $marked = [];
        $events = [];
        $job = new ScheduledJob("test", $this->schedule($due, $marked), static function () use (&$events, &$marked): void {
            $events[] = "job ran after " . count($marked) . " mark(s)";
        });

        $this->assertTrue($job->runIfDue($this->at("2026-10-20 10:00:00")));
        $this->assertSame(["10:00:00"], $marked);
        $this->assertSame(["job ran after 1 mark(s)"], $events);
    }

    public function testDoesNotRunWhenNotDue(): void
    {
        $due = false;
        $marked = [];
        $ran = false;
        $job = new ScheduledJob("test", $this->schedule($due, $marked), static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($job->runIfDue($this->at("2026-10-20 10:00:00")));
        $this->assertFalse($ran);
        $this->assertSame([], $marked);
    }

    public function testConsultsTheScheduleAtMostOncePerCheckInterval(): void
    {
        $due = false;
        $marked = [];
        $job = new ScheduledJob("test", $this->schedule($due, $marked), static fn(): mixed => null, 60);

        $this->assertFalse($job->runIfDue($this->at("2026-10-20 10:00:00")));

        // Became due, but the schedule isn't asked again until the interval elapsed.
        $due = true;
        $this->assertFalse($job->runIfDue($this->at("2026-10-20 10:00:30")));
        $this->assertFalse($job->runIfDue($this->at("2026-10-20 10:00:59")));
        $this->assertTrue($job->runIfDue($this->at("2026-10-20 10:01:00")));
    }

    public function testAFailingJobLeavesTheScheduleMarked(): void
    {
        $due = true;
        $marked = [];
        $job = new ScheduledJob("test", $this->schedule($due, $marked), static function (): void {
            throw new RuntimeException("boom");
        });

        try {
            $job->runIfDue($this->at("2026-10-20 10:00:00"));
            $this->fail("The job's exception should propagate");
        } catch (RuntimeException $e) {
            $this->assertSame("boom", $e->getMessage());
        }

        $this->assertSame(["10:00:00"], $marked);
    }

    public function testDescribesItselfWithItsSchedule(): void
    {
        $due = false;
        $marked = [];
        $job = new ScheduledJob("sync_repositories", $this->schedule($due, $marked), static fn(): mixed => null);

        $this->assertSame("sync_repositories", $job->getName());
        $this->assertSame("sync_repositories (test schedule)", $job->describe());
    }

    public function testRejectsAnEmptyName(): void
    {
        $due = false;
        $marked = [];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("name cannot be empty");

        $this->assertInstanceOf(ScheduledJob::class, new ScheduledJob(" ", $this->schedule($due, $marked), static fn(): mixed => null));
    }
}
