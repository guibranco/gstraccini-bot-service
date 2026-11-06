<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use DateTimeImmutable;
use ErrorException;
use GuiBranco\GStracciniBot\Library\RecreateRequester;
use GuiBranco\GStracciniBot\Library\RecreateRequestStatus;
use PHPUnit\Framework\TestCase;

/**
 * How RecreateRequester tells whether the dependency bot is among those who
 * reacted to a request, and what it does when it can't tell.
 */
class RecreateRequesterReactionsTest extends TestCase
{
    /** The request, as it is posted on a Dependabot pull request. */
    private const DEPENDABOT_REQUEST = "<!--GStraccini:3e235d6b741b467bd5b06da1615c41b076cb1c0c-->\n@dependabot recreate";

    /** The reactions endpoint of the request. */
    private const REACTIONS_URL = "issues/comments/6322533681/reactions";

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
     * Starts each test with a pull request whose only comment is a request
     * posted two weeks ago that has one reaction.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->requester = new RecreateRequester();
        $this->github = new FakeGitHub();
        $this->fixtures = new RecreateRequestFixtures();
        $this->github->comments(1, [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 1)]);

    }//end setUp()


    /**
     * Asks for the recreate of the Dependabot pull request, two weeks after
     * the request.
     *
     * @return RecreateRequestStatus
     */
    private function request(): RecreateRequestStatus
    {
        $now = new DateTimeImmutable(RecreateRequestFixtures::TWO_WEEKS_LATER);

        return $this->requester->request($this->github->metadata(), RecreateRequestFixtures::HEAD_SHA, "dependabot[bot]", $now);

    }//end request()


    /**
     * A request someone else reacted to was still never received by the
     * dependency bot, so it is posted again.
     *
     * @return void
     */
    public function testAReactionFromSomeoneElseIsNotTheAcknowledgement(): void
    {
        $this->github->reactions(RecreateRequestFixtures::REQUEST_ID, [$this->fixtures->reaction("guibranco")]);

        $status = $this->request();

        $this->assertSame(RecreateRequestStatus::UNACKNOWLEDGED, $status->status());
        $this->assertSame([self::DEPENDABOT_REQUEST], $this->github->posted());

    }//end testAReactionFromSomeoneElseIsNotTheAcknowledgement()


    /**
     * The rollup can still count a reaction that is not listed any more.
     *
     * @return void
     */
    public function testAReactionThatIsNotListedAnyMoreIsNotTheAcknowledgement(): void
    {
        $this->github->reactions(RecreateRequestFixtures::REQUEST_ID, []);

        $status = $this->request();

        $this->assertSame(RecreateRequestStatus::UNACKNOWLEDGED, $status->status());
        $this->assertSame([self::DEPENDABOT_REQUEST], $this->github->posted());

    }//end testAReactionThatIsNotListedAnyMoreIsNotTheAcknowledgement()


    /**
     * Logins are not case-sensitive on GitHub, so neither is the comparison.
     *
     * @return void
     */
    public function testTheLoginOfTheDependencyBotIsMatchedWhateverItsCase(): void
    {
        $this->github->reactions(RecreateRequestFixtures::REQUEST_ID, [$this->fixtures->reaction("Dependabot[bot]")]);

        $status = $this->request();

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertSame([], $this->github->posted());

    }//end testTheLoginOfTheDependencyBotIsMatchedWhateverItsCase()


    /**
     * A reaction whose user GitHub no longer has is passed over quietly,
     * which the test checks by turning anything PHP has to say into an
     * error, and it doesn't hide the reactions after it.
     *
     * @return void
     */
    public function testAReactionWithoutAUserIsPassedOver(): void
    {
        $anonymous = $this->fixtures->reaction("ghost");
        $anonymous->user = null;
        $this->github->reactions(RecreateRequestFixtures::REQUEST_ID, [$anonymous, $this->fixtures->reaction("dependabot[bot]")]);

        set_error_handler(fn (int $severity, string $message): bool => throw new ErrorException($message, 0, $severity));
        try {
            $status = $this->request();
        } finally {
            restore_error_handler();
        }

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());

    }//end testAReactionWithoutAUserIsPassedOver()


    /**
     * Without knowing who reacted, the request is not posted again on a
     * guess.
     *
     * @return void
     */
    public function testTakesTheRequestAsAcknowledgedWhenTheReactionsCannotBeListed(): void
    {
        $this->github->fail(self::REACTIONS_URL, 502);

        $status = $this->request();

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertSame([], $this->github->posted());

    }//end testTakesTheRequestAsAcknowledgedWhenTheReactionsCannotBeListed()


    /**
     * Neither is it when GitHub answers with something that is not a list.
     *
     * @return void
     */
    public function testTakesTheRequestAsAcknowledgedWhenTheReactionsAreNotAList(): void
    {
        $this->github->answer(self::REACTIONS_URL, "{\"message\": \"Moved Permanently\"}");

        $status = $this->request();

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertSame([], $this->github->posted());

    }//end testTakesTheRequestAsAcknowledgedWhenTheReactionsAreNotAList()


    /**
     * A comment without an id has no reactions endpoint to ask.
     *
     * @return void
     */
    public function testTakesARequestWithoutAnIdAsAcknowledged(): void
    {
        $request = $this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 1);
        unset($request->id);
        $this->github->comments(1, [$request]);

        $status = $this->request();

        $this->assertSame(RecreateRequestStatus::ACKNOWLEDGED, $status->status());
        $this->assertCount(1, $this->github->requests());

    }//end testTakesARequestWithoutAnIdAsAcknowledged()


}//end class
