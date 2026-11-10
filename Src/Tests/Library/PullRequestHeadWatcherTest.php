<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\PullRequestHeadWatcher;
use PHPUnit\Framework\TestCase;

/**
 * How PullRequestHeadWatcher waits for a pull request's head to move after
 * the bot pushed onto its branch.
 */
class PullRequestHeadWatcherTest extends TestCase
{
    /** The pull request endpoint. */
    private const PULL_REQUEST_URL = "repos/owner/repo/pulls/1";

    /** The fetch of the pull request. */
    private const FETCH = "GET fake-token repos/owner/repo/pulls/1";

    /** The head before the bot pushed. */
    private const OLD_HEAD = "1111111111111111111111111111111111111111";

    /** The head after the bot pushed. */
    private const NEW_HEAD = "2222222222222222222222222222222222222222";

    /**
     * Answers for GitHub.
     *
     * @var FakeGitHub
     */
    private FakeGitHub $github;

    /**
     * The pauses taken, in seconds.
     *
     * @var array
     */
    private array $pauses = [];


    protected function setUp(): void
    {
        $this->github = new FakeGitHub();
        $this->pauses = [];

    }//end setUp()


    private function head(string $sha): void
    {
        $this->github->answer(self::PULL_REQUEST_URL, json_encode(["head" => ["sha" => $sha]]));

    }//end head()


    /**
     * A watcher whose pauses are recorded, and which runs a step on each pause.
     */
    private function watcher(?callable $onPause = null): PullRequestHeadWatcher
    {
        return new PullRequestHeadWatcher(function (int $seconds) use ($onPause): void {
            $this->pauses[] = $seconds;
            if ($onPause !== null) {
                $onPause(count($this->pauses));
            }
        });

    }//end watcher()


    public function testReturnsTheNewHeadRightAwayWhenItAlreadyMoved(): void
    {
        $this->head(self::NEW_HEAD);

        $sha = $this->watcher()->waitForNewHead(FakeGitHub::TOKEN, self::PULL_REQUEST_URL, self::OLD_HEAD);

        $this->assertSame(self::NEW_HEAD, $sha);
        $this->assertSame([self::FETCH], $this->github->requests());
        $this->assertSame([], $this->pauses);

    }//end testReturnsTheNewHeadRightAwayWhenItAlreadyMoved()


    public function testKeepsFetchingUntilTheHeadMoves(): void
    {
        $this->head(self::OLD_HEAD);

        $sha = $this->watcher(function (int $pause): void {
            if ($pause === 2) {
                $this->head(self::NEW_HEAD);
            }
        })->waitForNewHead(FakeGitHub::TOKEN, self::PULL_REQUEST_URL, self::OLD_HEAD);

        $this->assertSame(self::NEW_HEAD, $sha);
        $this->assertCount(3, $this->github->requests());
        $this->assertSame([PullRequestHeadWatcher::DELAY_SECONDS, PullRequestHeadWatcher::DELAY_SECONDS], $this->pauses);

    }//end testKeepsFetchingUntilTheHeadMoves()


    public function testGivesUpWhenTheHeadNeverMoves(): void
    {
        $this->head(self::OLD_HEAD);

        $sha = $this->watcher()->waitForNewHead(FakeGitHub::TOKEN, self::PULL_REQUEST_URL, self::OLD_HEAD);

        $this->assertNull($sha);
        $this->assertCount(PullRequestHeadWatcher::ATTEMPTS, $this->github->requests());
        $this->assertCount(PullRequestHeadWatcher::ATTEMPTS - 1, $this->pauses);

    }//end testGivesUpWhenTheHeadNeverMoves()


    public function testKeepsTryingWhenGitHubFails(): void
    {
        $this->github->fail(self::PULL_REQUEST_URL, 502);

        $sha = $this->watcher(function (int $pause): void {
            if ($pause === 1) {
                $this->github = new FakeGitHub();
                $this->head(self::NEW_HEAD);
            }
        })->waitForNewHead(FakeGitHub::TOKEN, self::PULL_REQUEST_URL, self::OLD_HEAD);

        $this->assertSame(self::NEW_HEAD, $sha);

    }//end testKeepsTryingWhenGitHubFails()


}//end class
