<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use DateTimeImmutable;
use GuiBranco\GStracciniBot\Library\RecreateRequestAnalyzer;
use GuiBranco\GStracciniBot\Library\RecreateRequestStatus;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * What RecreateRequestAnalyzer takes as the dependency bot's acknowledgement
 * of a request, and what it makes of a request it can't judge.
 */
class RecreateRequestAcknowledgementTest extends TestCase
{
    /** The head commit of the pull request. */
    private const HEAD_SHA = RecreateRequestFixtures::HEAD_SHA;

    /**
     * The analyzer under test.
     *
     * @var RecreateRequestAnalyzer
     */
    private RecreateRequestAnalyzer $analyzer;

    /**
     * Builds the comments.
     *
     * @var RecreateRequestFixtures
     */
    private RecreateRequestFixtures $fixtures;

    /**
     * Two weeks after the request, long past the timeout.
     *
     * @var DateTimeImmutable
     */
    private DateTimeImmutable $twoWeeksLater;


    /**
     * Starts each test with an analyzer and the fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->analyzer = new RecreateRequestAnalyzer();
        $this->fixtures = new RecreateRequestFixtures();
        $this->twoWeeksLater = new DateTimeImmutable(RecreateRequestFixtures::TWO_WEEKS_LATER);

    }//end setUp()


    /**
     * The reaction of the dependency bot is its acknowledgement, whoever
     * else reacted.
     *
     * @return void
     */
    public function testAcknowledgedWhenTheDependencyBotIsAmongThoseWhoReacted(): void
    {
        $comments = [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 2)];

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->fixtures->after(5), fn (): bool => true);

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertSame(1, $status->requests());
        $this->assertSame(RecreateRequestFixtures::REQUESTED_AT, $status->lastRequestedAt());

    }//end testAcknowledgedWhenTheDependencyBotIsAmongThoseWhoReacted()


    /**
     * Time doesn't undo an acknowledgement: if the conflict is still there,
     * asking again would not change the dependency bot's answer.
     *
     * @return void
     */
    public function testAnAcknowledgedRequestIsNeverPostedAgainHoweverOldItGets(): void
    {
        $comments = [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 1)];

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->fixtures->after((30 * 86400)), fn (): bool => true);

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertFalse($status->mustRequest());

    }//end testAnAcknowledgedRequestIsNeverPostedAgainHoweverOldItGets()


    /**
     * One acknowledged request for the head commit is enough, whichever of
     * them it is.
     *
     * @return void
     */
    public function testAcknowledgedWhenAnyRequestForTheHeadCommitWasAcknowledged(): void
    {
        $comments = [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0)];
        $comments[] = $this->fixtures->request("2026-10-22T08:00:00Z", 1);
        $comments[] = $this->fixtures->request("2026-10-23T08:00:00Z", 0);

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->twoWeeksLater, fn (): bool => true);

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertSame(3, $status->requests());

    }//end testAcknowledgedWhenAnyRequestForTheHeadCommitWasAcknowledged()


    /**
     * The acknowledgement of the request for a previous head commit says
     * nothing about the request for this one.
     *
     * @return void
     */
    public function testAReactionOnARequestForAnotherHeadCommitDoesNotCount(): void
    {
        $comments = [$this->fixtures->requestFor(RecreateRequestFixtures::OTHER_SHA, "2026-10-15T06:00:00Z", 1)];
        $comments[] = $this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0);
        $nobodyToAsk = fn (): bool => throw new LogicException("Who reacted was asked about a request for another head commit.");

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->twoWeeksLater, $nobodyToAsk);

        $this->assertSame(RecreateRequestStatus::UNACKNOWLEDGED, $status->status());
        $this->assertSame(1, $status->requests());

    }//end testAReactionOnARequestForAnotherHeadCommitDoesNotCount()


    /**
     * A request someone else reacted to is still one the dependency bot
     * never received.
     *
     * @return void
     */
    public function testAReactionFromSomeoneElseIsNotTheAcknowledgement(): void
    {
        $comments = [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 1)];

        $waiting = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->fixtures->after(5), fn (): bool => false);
        $timedOut = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->fixtures->after(86400), fn (): bool => false);

        $this->assertSame(RecreateRequestStatus::WAITING, $waiting->status());
        $this->assertSame(RecreateRequestStatus::UNACKNOWLEDGED, $timedOut->status());

    }//end testAReactionFromSomeoneElseIsNotTheAcknowledgement()


    /**
     * Telling who reacted takes a call to GitHub, so it is only asked about
     * the requests for the head commit that have reactions.
     *
     * @return void
     */
    public function testWhoReactedIsOnlyAskedAboutRequestsThatHaveReactions(): void
    {
        $withReactions = $this->fixtures->request("2026-10-22T08:00:00Z", 1);
        $otherComment = $this->fixtures->comment("Quality Gate passed", "2026-10-15T05:54:00Z");
        $otherComment->reactions->total_count = 3;
        $comments = [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0)];
        $comments[] = $otherComment;
        $comments[] = $this->fixtures->requestFor(RecreateRequestFixtures::OTHER_SHA, "2026-10-15T06:00:00Z", 1);
        $comments[] = $withReactions;
        $asked = [];
        $someoneElse = function (object $comment) use (&$asked): bool {
            $asked[] = $comment;

            return false;
        };

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->twoWeeksLater, $someoneElse);

        $this->assertSame([$withReactions], $asked);
        $this->assertSame(RecreateRequestStatus::UNACKNOWLEDGED, $status->status());
        $this->assertSame(2, $status->requests());

    }//end testWhoReactedIsOnlyAskedAboutRequestsThatHaveReactions()


    /**
     * Once a request is known to be acknowledged there is nothing left to
     * find out about the others.
     *
     * @return void
     */
    public function testWhoReactedIsNotAskedAgainOnceARequestIsAcknowledged(): void
    {
        $comments = [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 1)];
        $comments[] = $this->fixtures->request("2026-10-22T08:00:00Z", 1);
        $asked = 0;
        $theBot = function () use (&$asked): bool {
            $asked++;

            return true;
        };

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->twoWeeksLater, $theBot);

        $this->assertSame(1, $asked);
        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertSame(2, $status->requests());

    }//end testWhoReactedIsNotAskedAgainOnceARequestIsAcknowledged()


    /**
     * A request that doesn't say whether it has reactions is not posted
     * again on a guess.
     *
     * @return void
     */
    public function testARequestWithoutTheReactionsRollupIsTakenAsAcknowledged(): void
    {
        $request = $this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0);
        unset($request->reactions);
        $nobodyToAsk = fn (): bool => throw new LogicException("Who reacted was asked about a request without the reactions rollup.");

        $status = $this->analyzer->analyze([$request], self::HEAD_SHA, $this->twoWeeksLater, $nobodyToAsk);

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());

    }//end testARequestWithoutTheReactionsRollupIsTakenAsAcknowledged()


    /**
     * Neither is a request that doesn't say when it was posted: it is waited
     * for, however long ago it may have been.
     *
     * @return void
     */
    public function testARequestWithoutAReadableDateNeverTimesOut(): void
    {
        $unreadable = $this->fixtures->request("not a date", 0);
        $undated = $this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0);
        unset($undated->created_at);
        $nobodyToAsk = fn (): bool => throw new LogicException("Who reacted was asked about a request without reactions.");

        $fromUnreadable = $this->analyzer->analyze([$unreadable], self::HEAD_SHA, $this->twoWeeksLater, $nobodyToAsk);
        $fromUndated = $this->analyzer->analyze([$undated], self::HEAD_SHA, $this->twoWeeksLater, $nobodyToAsk);

        $this->assertSame(RecreateRequestStatus::WAITING, $fromUnreadable->status());
        $this->assertSame(RecreateRequestStatus::WAITING, $fromUndated->status());
        $this->assertSame(1, $fromUndated->requests());
        $this->assertNull($fromUndated->lastRequestedAt());

    }//end testARequestWithoutAReadableDateNeverTimesOut()


}//end class
