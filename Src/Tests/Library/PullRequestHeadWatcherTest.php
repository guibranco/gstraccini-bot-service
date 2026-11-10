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
    private array $pauses;

    /**
     * After which pause GitHub starts answering with the new head, 0 for never.
     *
     * @var integer
     */
    private int $movesAfter;

    /**
     * The watcher under test, whose pauses are recorded.
     *
     * @var PullRequestHeadWatcher
     */
    private PullRequestHeadWatcher $watcher;


    /**
     * Starts with a GitHub that answers with nothing and a head that never moves.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->github     = new FakeGitHub();
        $this->pauses     = [];
        $this->movesAfter = 0;
        $this->watcher    = new PullRequestHeadWatcher($this->pause(...));

    }//end setUp()


    /**
     * Sets up the head GitHub answers with.
     *
     * @param string $sha The head commit.
     *
     * @return void
     */
    private function head(string $sha): void
    {
        $this->github->answer(self::PULL_REQUEST_URL, json_encode(["head" => ["sha" => $sha]]));

    }//end head()


    /**
     * Records a pause, and moves the head when it is the pause it is set to move after.
     *
     * @param integer $seconds The length of the pause.
     *
     * @return void
     */
    private function pause(int $seconds): void
    {
        $this->pauses[] = $seconds;
        if (count($this->pauses) === $this->movesAfter) {
            $this->github = new FakeGitHub();
            $this->head(self::NEW_HEAD);
        }

    }//end pause()


    /**
     * A head that already moved is returned at once.
     *
     * @return void
     */
    public function testReturnsAnAlreadyMovedHead(): void
    {
        $this->head(self::NEW_HEAD);

        $sha = $this->watcher->waitForNewHead(FakeGitHub::TOKEN, self::PULL_REQUEST_URL, self::OLD_HEAD);

        $this->assertSame(self::NEW_HEAD, $sha);
        $this->assertSame([self::FETCH], $this->github->requests());
        $this->assertSame([], $this->pauses);

    }//end testReturnsAnAlreadyMovedHead()


    /**
     * The pull request is fetched again after each pause until its head moves.
     *
     * @return void
     */
    public function testKeepsFetchingUntilTheHeadMoves(): void
    {
        $this->head(self::OLD_HEAD);
        $this->movesAfter = 2;

        $sha = $this->watcher->waitForNewHead(FakeGitHub::TOKEN, self::PULL_REQUEST_URL, self::OLD_HEAD);

        $this->assertSame(self::NEW_HEAD, $sha);
        $this->assertSame([self::FETCH], $this->github->requests());
        $this->assertSame([PullRequestHeadWatcher::DELAY_SECONDS, PullRequestHeadWatcher::DELAY_SECONDS], $this->pauses);

    }//end testKeepsFetchingUntilTheHeadMoves()


    /**
     * Nothing is returned when the head never moves.
     *
     * @return void
     */
    public function testGivesUpWhenTheHeadNeverMoves(): void
    {
        $this->head(self::OLD_HEAD);

        $sha = $this->watcher->waitForNewHead(FakeGitHub::TOKEN, self::PULL_REQUEST_URL, self::OLD_HEAD);

        $this->assertNull($sha);
        $this->assertCount(PullRequestHeadWatcher::ATTEMPTS, $this->github->requests());
        $this->assertCount((PullRequestHeadWatcher::ATTEMPTS - 1), $this->pauses);

    }//end testGivesUpWhenTheHeadNeverMoves()


    /**
     * A failed fetch is retried after a pause.
     *
     * @return void
     */
    public function testKeepsTryingWhenGitHubFails(): void
    {
        $this->github->fail(self::PULL_REQUEST_URL, 502);
        $this->movesAfter = 1;

        $sha = $this->watcher->waitForNewHead(FakeGitHub::TOKEN, self::PULL_REQUEST_URL, self::OLD_HEAD);

        $this->assertSame(self::NEW_HEAD, $sha);

    }//end testKeepsTryingWhenGitHubFails()


}//end class
