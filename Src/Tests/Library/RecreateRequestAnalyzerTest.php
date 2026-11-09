<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use Closure;
use DateTimeImmutable;
use GuiBranco\GStracciniBot\Library\RecreateRequestAnalyzer;
use GuiBranco\GStracciniBot\Library\RecreateRequestStatus;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * How RecreateRequestAnalyzer reads the requests posted for a head commit:
 * how many there are, when the last one was, and what that adds up to as
 * time passes without a reaction.
 */
class RecreateRequestAnalyzerTest extends TestCase
{
    /** The head commit of the pull request. */
    private const HEAD_SHA = RecreateRequestFixtures::HEAD_SHA;

    /** How long a request may go without an acknowledgement, in seconds. */
    private const TIMEOUT = RecreateRequestAnalyzer::ACKNOWLEDGEMENT_TIMEOUT_SECONDS;

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
     * Stands for the look at who reacted in tests where no request has a
     * reaction: there is nobody to ask about, so asking fails the test.
     *
     * @var Closure
     */
    private Closure $nobodyToAsk;


    /**
     * Starts each test with an analyzer and the fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->analyzer = new RecreateRequestAnalyzer();
        $this->fixtures = new RecreateRequestFixtures();
        $this->nobodyToAsk = fn (): bool => throw new LogicException("Who reacted was asked about a request without reactions.");

    }//end setUp()


    /**
     * The requests already on pull requests carry this marker, so it has to
     * stay as it is for them to be found.
     *
     * @return void
     */
    public function testMarkerKeepsTheFormatOfTheRequestsAlreadyPosted(): void
    {
        $this->assertSame("<!--GStraccini:3e235d6b741b467bd5b06da1615c41b076cb1c0c-->", $this->analyzer->marker(self::HEAD_SHA));

    }//end testMarkerKeepsTheFormatOfTheRequestsAlreadyPosted()


    /**
     * A pull request without comments was never asked to be recreated.
     *
     * @return void
     */
    public function testNotRequestedWhenThereAreNoComments(): void
    {
        $status = $this->analyzer->analyze([], self::HEAD_SHA, $this->fixtures->after(0), $this->nobodyToAsk);

        $this->assertSame(RecreateRequestStatus::NOT_REQUESTED, $status->status());
        $this->assertSame(0, $status->requests());
        $this->assertNull($status->lastRequestedAt());

    }//end testNotRequestedWhenThereAreNoComments()


    /**
     * Neither a comment without the marker nor a request for a previous head
     * commit is a request for this one: that recreate happened, and this is
     * a new conflict. A comment without a body is passed over quietly, which
     * the test checks by turning anything PHP has to say into an error.
     *
     * @return void
     */
    public function testNotRequestedWhenNoCommentCarriesTheMarkerOfTheHeadCommit(): void
    {
        $withoutBody = $this->fixtures->comment("", "2026-10-15T05:55:00Z");
        $withoutBody->body = null;
        $comments = [$this->fixtures->comment("Review skipped\n\nBot user detected.", "2026-10-15T05:54:00Z")];
        $comments[] = $this->fixtures->comment("@dependabot recreate", "2026-10-15T05:54:30Z");
        $comments[] = $withoutBody;
        $comments[] = $this->fixtures->requestFor(RecreateRequestFixtures::OTHER_SHA, "2026-10-15T06:00:00Z", 1);

        set_error_handler(fn (int $severity, string $message) => $this->fail("PHP error {$severity}: {$message}"));
        try {
            $status = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->fixtures->after(86400), $this->nobodyToAsk);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(RecreateRequestStatus::NOT_REQUESTED, $status->status());
        $this->assertSame(0, $status->requests());

    }//end testNotRequestedWhenNoCommentCarriesTheMarkerOfTheHeadCommit()


    /**
     * The dependency bots react within seconds, but a request is given the
     * whole timeout before it counts as never received.
     *
     * @return void
     */
    public function testWaitingWhileTheRequestIsRecentAndHasNoReactionYet(): void
    {
        $comments = [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0)];

        $justPosted = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->fixtures->after(2), $this->nobodyToAsk);
        $lastSecond = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->fixtures->after((self::TIMEOUT - 1)), $this->nobodyToAsk);

        $this->assertSame(RecreateRequestStatus::WAITING, $justPosted->status());
        $this->assertSame(RecreateRequestStatus::WAITING, $lastSecond->status());
        $this->assertSame(1, $lastSecond->requests());

    }//end testWaitingWhileTheRequestIsRecentAndHasNoReactionYet()


    /**
     * From the timeout on, a request without a reaction was never received.
     *
     * @return void
     */
    public function testUnacknowledgedOnceTheRequestTimedOutWithoutAnyReaction(): void
    {
        $comments = [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0)];

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->fixtures->after(self::TIMEOUT), $this->nobodyToAsk);

        $this->assertSame(RecreateRequestStatus::UNACKNOWLEDGED, $status->status());
        $this->assertSame(1, $status->requests());
        $this->assertSame(RecreateRequestFixtures::REQUESTED_AT, $status->lastRequestedAt());

    }//end testUnacknowledgedOnceTheRequestTimedOutWithoutAnyReaction()


    /**
     * The case of guibranco/grimoire-ui#350: requested on 2026-10-21, never
     * reacted to, and still there with the same head commit two weeks later.
     *
     * @return void
     */
    public function testUnacknowledgedForARequestLeftWithoutReactionForTwoWeeks(): void
    {
        $comments = [$this->fixtures->comment("### Labels\n\nThe following labels could not be found: `dependencies`.", "2026-10-15T05:54:00Z")];
        $comments[] = $this->fixtures->comment("Review skipped\n\nBot user detected.", "2026-10-15T05:54:30Z");
        $comments[] = $this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0);
        $now = new DateTimeImmutable(RecreateRequestFixtures::TWO_WEEKS_LATER);

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, $now, $this->nobodyToAsk);

        $this->assertSame(RecreateRequestStatus::UNACKNOWLEDGED, $status->status());
        $this->assertSame(1, $status->requests());

    }//end testUnacknowledgedForARequestLeftWithoutReactionForTwoWeeks()


    /**
     * A request that was posted again starts a new wait.
     *
     * @return void
     */
    public function testTheTimeoutCountsFromTheLastRequest(): void
    {
        $comments = [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0)];
        $comments[] = $this->fixtures->request("2026-10-22T08:00:00Z", 0);

        $waiting = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-10-22T08:30:00Z"), $this->nobodyToAsk);
        $timedOut = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-10-22T09:00:00Z"), $this->nobodyToAsk);

        $this->assertSame(RecreateRequestStatus::WAITING, $waiting->status());
        $this->assertSame(2, $waiting->requests());
        $this->assertSame("2026-10-22T08:00:00Z", $waiting->lastRequestedAt());
        $this->assertSame(RecreateRequestStatus::UNACKNOWLEDGED, $timedOut->status());
        $this->assertSame(2, $timedOut->requests());

    }//end testTheTimeoutCountsFromTheLastRequest()


    /**
     * The last request is the one posted last, not the one listed last.
     *
     * @return void
     */
    public function testTheLastRequestIsFoundWhateverTheOrderOfTheComments(): void
    {
        $comments = [$this->fixtures->request("2026-10-22T08:00:00Z", 0)];
        $comments[] = $this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0);

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-10-22T08:30:00Z"), $this->nobodyToAsk);

        $this->assertSame("2026-10-22T08:00:00Z", $status->lastRequestedAt());
        $this->assertSame(RecreateRequestStatus::WAITING, $status->status());

    }//end testTheLastRequestIsFoundWhateverTheOrderOfTheComments()


    /**
     * The bot stops asking once the requests allowed for a head commit all
     * timed out.
     *
     * @return void
     */
    public function testGivesUpAfterTheMaximumNumberOfUnacknowledgedRequests(): void
    {
        $comments = $this->fixtures->unanswered(RecreateRequestStatus::MAX_REQUESTS);
        $now = new DateTimeImmutable(RecreateRequestFixtures::TWO_WEEKS_LATER);

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, $now, $this->nobodyToAsk);

        $this->assertSame(RecreateRequestStatus::GAVE_UP, $status->status());
        $this->assertSame(RecreateRequestStatus::MAX_REQUESTS, $status->requests());

    }//end testGivesUpAfterTheMaximumNumberOfUnacknowledgedRequests()


    /**
     * The last request allowed gets its wait like the others.
     *
     * @return void
     */
    public function testStillWaitsForTheLastAllowedRequestBeforeGivingUp(): void
    {
        $comments = $this->fixtures->unanswered(RecreateRequestStatus::MAX_REQUESTS);

        $status = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-10-23T17:45:00Z"), $this->nobodyToAsk);

        $this->assertSame(RecreateRequestStatus::WAITING, $status->status());

    }//end testStillWaitsForTheLastAllowedRequestBeforeGivingUp()


}//end class
