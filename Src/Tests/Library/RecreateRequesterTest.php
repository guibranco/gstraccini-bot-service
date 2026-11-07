<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use DateTimeImmutable;
use GuiBranco\GStracciniBot\Library\RecreateRequester;
use GuiBranco\GStracciniBot\Library\RecreateRequestStatus;
use GuiBranco\Pancake\RequestException;
use PHPUnit\Framework\TestCase;

/**
 * When RecreateRequester posts the recreate request of a conflicting pull
 * request, and what it asks GitHub to find out.
 */
class RecreateRequesterTest extends TestCase
{
    /** The head commit of the pull request. */
    private const HEAD_SHA = RecreateRequestFixtures::HEAD_SHA;

    /** The request, as it is posted on a Dependabot pull request. */
    private const DEPENDABOT_REQUEST = "<!--GStraccini:3e235d6b741b467bd5b06da1615c41b076cb1c0c-->\n@dependabot recreate";

    /** The request, as it is posted on a Depfu pull request. */
    private const DEPFU_REQUEST = "<!--GStraccini:3e235d6b741b467bd5b06da1615c41b076cb1c0c-->\n@depfu recreate";

    /** The listing of the first page of comments, with the bot's token. */
    private const FIRST_PAGE = "GET fake-token repos/owner/repo/issues/1/comments?per_page=100&page=1";

    /** The listing of the second page of comments. */
    private const SECOND_PAGE = "GET fake-token repos/owner/repo/issues/1/comments?per_page=100&page=2";

    /** The look at who reacted to the request. */
    private const REACTIONS = "GET fake-token repos/owner/repo/issues/comments/6322533681/reactions?per_page=100";

    /** The posting of the request, with the owner's token. */
    private const POSTING = "POST fake-user-token repos/owner/repo/issues/1/comments";

    /**
     * The requester under test.
     *
     * @var RecreateRequester
     */
    private RecreateRequester $requester;

    /**
     * Answers for GitHub.
     *
     * @var FakeGitHub
     */
    private FakeGitHub $github;

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
     * Starts each test with a requester, a GitHub that knows nothing and
     * the fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->requester = new RecreateRequester();
        $this->github = new FakeGitHub();
        $this->fixtures = new RecreateRequestFixtures();
        $this->twoWeeksLater = new DateTimeImmutable(RecreateRequestFixtures::TWO_WEEKS_LATER);

    }//end setUp()


    /**
     * The first request is posted with the owner's token, after reading the
     * comments with the bot's.
     *
     * @return void
     */
    public function testRequestsTheRecreateOfADependabotPullRequest(): void
    {
        $this->github->comments(1, [$this->fixtures->comment("Review skipped", "2026-10-15T05:54:00Z")]);

        $status = $this->requester->request($this->github->metadata(), self::HEAD_SHA, "dependabot[bot]", $this->twoWeeksLater);

        $this->assertSame(RecreateRequestStatus::NOT_REQUESTED, $status->status());
        $this->assertSame([self::DEPENDABOT_REQUEST], $this->github->posted());
        $this->assertSame([self::FIRST_PAGE, self::POSTING], $this->github->requests());

    }//end testRequestsTheRecreateOfADependabotPullRequest()


    /**
     * Depfu is asked with its own command.
     *
     * @return void
     */
    public function testRequestsTheRecreateOfADepfuPullRequest(): void
    {
        $this->github->comments(1, []);

        $this->requester->request($this->github->metadata(), self::HEAD_SHA, "depfu[bot]", $this->twoWeeksLater);

        $this->assertSame([self::DEPFU_REQUEST], $this->github->posted());

    }//end testRequestsTheRecreateOfADepfuPullRequest()


    /**
     * A request Dependabot reacted to is not posted again, however long ago
     * it was.
     *
     * @return void
     */
    public function testDoesNotRequestAgainOnceDependabotAcknowledgedTheRequest(): void
    {
        $this->github->comments(1, [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 1)]);
        $this->github->reactions(RecreateRequestFixtures::REQUEST_ID, [$this->fixtures->reaction("dependabot[bot]")]);

        $status = $this->requester->request($this->github->metadata(), self::HEAD_SHA, "dependabot[bot]", $this->twoWeeksLater);

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertSame([], $this->github->posted());
        $this->assertSame([self::FIRST_PAGE, self::REACTIONS], $this->github->requests());

    }//end testDoesNotRequestAgainOnceDependabotAcknowledgedTheRequest()


    /**
     * On a Depfu pull request the reaction that counts is Depfu's.
     *
     * @return void
     */
    public function testDoesNotRequestAgainOnceDepfuAcknowledgedTheRequest(): void
    {
        $this->github->comments(1, [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 2)]);
        $this->github->reactions(RecreateRequestFixtures::REQUEST_ID, [$this->fixtures->reaction("guibranco"), $this->fixtures->reaction("depfu[bot]")]);

        $status = $this->requester->request($this->github->metadata(), self::HEAD_SHA, "depfu[bot]", $this->twoWeeksLater);

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertSame([], $this->github->posted());

    }//end testDoesNotRequestAgainOnceDepfuAcknowledgedTheRequest()


    /**
     * A request posted seconds ago may simply not have been reacted to yet.
     *
     * @return void
     */
    public function testDoesNotRequestAgainWhileWaitingForTheAcknowledgement(): void
    {
        $this->github->comments(1, [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0)]);

        $status = $this->requester->request($this->github->metadata(), self::HEAD_SHA, "dependabot[bot]", $this->fixtures->after(5));

        $this->assertSame(RecreateRequestStatus::WAITING, $status->status());
        $this->assertSame([], $this->github->posted());

    }//end testDoesNotRequestAgainWhileWaitingForTheAcknowledgement()


    /**
     * The case of guibranco/grimoire-ui#350: the request was posted,
     * Dependabot never reacted to it nor recreated the pull request, and
     * the bot kept taking it as requested for two weeks. A request without
     * reactions needs no look at who reacted.
     *
     * @return void
     */
    public function testRequestsAgainWhenTheRequestWasNeverAcknowledged(): void
    {
        $this->github->comments(1, [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0)]);

        $status = $this->requester->request($this->github->metadata(), self::HEAD_SHA, "dependabot[bot]", $this->twoWeeksLater);

        $this->assertSame(RecreateRequestStatus::UNACKNOWLEDGED, $status->status());
        $this->assertSame([self::DEPENDABOT_REQUEST], $this->github->posted());
        $this->assertSame([self::FIRST_PAGE, self::POSTING], $this->github->requests());

    }//end testRequestsAgainWhenTheRequestWasNeverAcknowledged()


    /**
     * The bot stops asking after three requests nobody answered.
     *
     * @return void
     */
    public function testGivesUpAfterThreeRequestsWereNeverAcknowledged(): void
    {
        $this->github->comments(1, $this->fixtures->unanswered(3));

        $status = $this->requester->request($this->github->metadata(), self::HEAD_SHA, "dependabot[bot]", $this->twoWeeksLater);

        $this->assertSame(RecreateRequestStatus::GAVE_UP, $status->status());
        $this->assertSame(3, $status->requests());
        $this->assertSame([], $this->github->posted());

    }//end testGivesUpAfterThreeRequestsWereNeverAcknowledged()


    /**
     * The requests of a long-lived pull request are among its newest
     * comments, past the first hundred, and the ones of every page count.
     *
     * @return void
     */
    public function testReadsTheRequestsOfEveryPageOfComments(): void
    {
        $firstPage = array_fill(0, 99, $this->fixtures->comment("Quality Gate passed", "2026-10-15T05:54:00Z"));
        $firstPage[] = $this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0);
        $this->github->comments(1, $firstPage);
        $this->github->comments(2, [$this->fixtures->request("2026-10-22T08:00:00Z", 1)]);
        $this->github->reactions(RecreateRequestFixtures::REQUEST_ID, [$this->fixtures->reaction("dependabot[bot]")]);

        $status = $this->requester->request($this->github->metadata(), self::HEAD_SHA, "dependabot[bot]", $this->twoWeeksLater);

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertSame(2, $status->requests());
        $this->assertSame([], $this->github->posted());
        $this->assertSame([self::FIRST_PAGE, self::SECOND_PAGE, self::REACTIONS], $this->github->requests());

    }//end testReadsTheRequestsOfEveryPageOfComments()


    /**
     * A listing that never comes to a short page is cut at thirty pages,
     * and what was listed is what the request is decided on.
     *
     * @return void
     */
    public function testStopsListingCommentsAfterThirtyPages(): void
    {
        $this->github->answer(FakeGitHub::COMMENTS_URL, json_encode(array_fill(0, 100, $this->fixtures->comment("Quality Gate passed", "2026-10-15T05:54:00Z"))));

        $status = $this->requester->request($this->github->metadata(), self::HEAD_SHA, "dependabot[bot]", $this->twoWeeksLater);

        $this->assertSame(RecreateRequestStatus::NOT_REQUESTED, $status->status());
        $this->assertCount(31, $this->github->requests());
        $this->assertSame([self::DEPENDABOT_REQUEST], $this->github->posted());

    }//end testStopsListingCommentsAfterThirtyPages()


    /**
     * A failed listing fails the item: with a partial list, a request that
     * is already there could be posted again.
     *
     * @return void
     */
    public function testDoesNotRequestWhenTheCommentsCannotBeListed(): void
    {
        $this->github->fail(FakeGitHub::COMMENTS_URL, 502);

        try {
            $this->requester->request($this->github->metadata(), self::HEAD_SHA, "dependabot[bot]", $this->twoWeeksLater);
            $this->fail("The failed listing went unnoticed.");
        } catch (RequestException $exception) {
            $this->assertSame(502, $exception->getCode());
            $this->assertSame([], $this->github->posted());
        }

    }//end testDoesNotRequestWhenTheCommentsCannotBeListed()


}//end class
