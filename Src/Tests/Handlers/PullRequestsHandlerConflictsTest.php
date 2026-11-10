<?php

namespace GuiBranco\GStracciniBot\Tests\Handlers;

use GuiBranco\GStracciniBot\Handlers\PullRequestsHandler;
use GuiBranco\GStracciniBot\Tests\Library\FakeGitHub;
use GuiBranco\GStracciniBot\Tests\Library\RecreateRequestFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * What PullRequestsHandler does about a pull request that has conflicts, as
 * seen from outside: the comment it posts, the line it writes to the
 * processing log and the warning it sends.
 */
class PullRequestsHandlerConflictsTest extends TestCase
{
    /** The request, as it is posted on a Dependabot pull request. */
    private const DEPENDABOT_REQUEST = "<!--GStraccini:3e235d6b741b467bd5b06da1615c41b076cb1c0c-->\n@dependabot recreate";

    /** The delivery the pull request is being processed for. */
    private const DELIVERY_ID = "00000000-0000-0000-0000-000000000000";

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
     * Receives the warnings of the handler.
     *
     * @var LogStreamSpy
     */
    private LogStreamSpy $warnings;

    /**
     * The handler under test.
     *
     * @var PullRequestsHandler
     */
    private PullRequestsHandler $handler;


    /**
     * Starts each test with a GitHub that knows nothing and a log stream
     * that listens.
     *
     * @return void
     */
    protected function setUp(): void
    {
        global $logStream;

        $this->github = new FakeGitHub();
        $this->fixtures = new RecreateRequestFixtures();
        $this->warnings = new LogStreamSpy();
        $logStream = $this->warnings;
        $this->handler = new PullRequestsHandler();

    }//end setUp()


    /**
     * Leaves the handlers without a log stream, as the other tests have them.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        global $logStream;

        $logStream = null;

    }//end tearDown()


    /**
     * Has the handler look at the conflicts of pull request 350.
     *
     * @param string $sender         Who opened the pull request.
     * @param string $mergeableState What GitHub says of it: `dirty` when it has conflicts.
     *
     * @return string What the handler wrote to the processing log.
     */
    private function resolveConflicts(string $sender, string $mergeableState): string
    {
        $pullRequest = new stdClass();
        $pullRequest->Number = 350;
        $pullRequest->Sender = $sender;
        $pullRequest->DeliveryIdText = self::DELIVERY_ID;
        $pullRequestUpdated = new stdClass();
        $pullRequestUpdated->mergeable = ($mergeableState === "clean");
        $pullRequestUpdated->mergeable_state = $mergeableState;
        $pullRequestUpdated->head = new stdClass();
        $pullRequestUpdated->head->sha = RecreateRequestFixtures::HEAD_SHA;
        $method = new ReflectionMethod(PullRequestsHandler::class, "resolveConflicts");

        ob_start();
        try {
            $method->invoke($this->handler, $this->github->metadata(), $pullRequest, $pullRequestUpdated);
        } finally {
            $output = ob_get_clean();
        }

        return $output;

    }//end resolveConflicts()


    /**
     * A conflicting Dependabot pull request nobody asked about yet gets the
     * request, and the log only has the line that announces it.
     *
     * @return void
     */
    public function testRequestsTheRecreateOfAConflictingDependabotPullRequest(): void
    {
        $this->github->comments(1, []);

        $output = $this->resolveConflicts("dependabot[bot]", "dirty");

        $this->assertSame([self::DEPENDABOT_REQUEST], $this->github->posted());
        $this->assertSame("State: dirty - Resolve conflicts - Recreate via bot - Sender: dependabot[bot] ☢️\n", $output);
        $this->assertSame([], $this->warnings->warnings());

    }//end testRequestsTheRecreateOfAConflictingDependabotPullRequest()


    /**
     * A request Dependabot acknowledged is reported and left alone.
     *
     * @return void
     */
    public function testReportsARequestTheDependencyBotAcknowledged(): void
    {
        $this->github->comments(1, [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 1)]);
        $this->github->reactions(RecreateRequestFixtures::REQUEST_ID, [$this->fixtures->reaction("dependabot[bot]")]);

        $output = $this->resolveConflicts("dependabot[bot]", "dirty");

        $this->assertSame([], $this->github->posted());
        $this->assertStringEndsWith("State: dirty - Resolve conflicts - Already requested to recreate - Sender: dependabot[bot] ⚠️\n", $output);
        $this->assertSame([], $this->warnings->warnings());

    }//end testReportsARequestTheDependencyBotAcknowledged()


    /**
     * The case of guibranco/grimoire-ui#350: a request left without a
     * reaction is posted again, and that goes to the log and to the warnings
     * with the figures of the request.
     *
     * @return void
     */
    public function testRequestsAgainAndWarnsWhenTheRequestWasNeverAcknowledged(): void
    {
        $this->github->comments(1, [$this->fixtures->request(RecreateRequestFixtures::REQUESTED_AT, 0)]);

        $output = $this->resolveConflicts("dependabot[bot]", "dirty");
        $warnings = $this->warnings->warnings();

        $this->assertSame([self::DEPENDABOT_REQUEST], $this->github->posted());
        $this->assertStringEndsWith("State: dirty - Resolve conflicts - Recreate request from 2026-10-21T17:40:38Z was never acknowledged, requesting again (2/3) - Sender: dependabot[bot] 🔁\n", $output);
        $this->assertCount(1, $warnings);
        $this->assertSame('PR {repo}#{pr} recreate request from {last_requested_at} was never acknowledged — requesting again', $warnings[0]["message"]);
        $this->assertSame(1, $warnings[0]["context"]["requests"]);
        $this->assertSame("2026-10-21T17:40:38Z", $warnings[0]["context"]["last_requested_at"]);
        $this->assertSame("owner/repo", $warnings[0]["context"]["repo"]);
        $this->assertSame(350, $warnings[0]["context"]["pr"]);
        $this->assertSame("dependabot[bot]", $warnings[0]["context"]["sender"]);
        $this->assertSame(RecreateRequestFixtures::HEAD_SHA, $warnings[0]["context"]["head_sha"]);
        $this->assertSame(LogStreamSpy::PULL_REQUESTS, $warnings[0]["category"]);
        $this->assertSame(self::DELIVERY_ID, $warnings[0]["traceId"]);

    }//end testRequestsAgainAndWarnsWhenTheRequestWasNeverAcknowledged()


    /**
     * After three requests nobody answered the handler stops asking, and
     * warns that the pull request is left with its conflicts.
     *
     * @return void
     */
    public function testGivesUpWithAWarningAfterThreeRequestsNobodyAnswered(): void
    {
        $this->github->comments(1, $this->fixtures->unanswered(3));

        $output = $this->resolveConflicts("dependabot[bot]", "dirty");
        $warnings = $this->warnings->warnings();

        $this->assertSame([], $this->github->posted());
        $this->assertStringEndsWith("State: dirty - Resolve conflicts - Recreate requested 3 times and never acknowledged, giving up - Sender: dependabot[bot] ❌\n", $output);
        $this->assertCount(1, $warnings);
        $this->assertSame('PR {repo}#{pr} still has conflicts — {requests} recreate requests were never acknowledged, giving up', $warnings[0]["message"]);
        $this->assertSame(3, $warnings[0]["context"]["requests"]);

    }//end testGivesUpWithAWarningAfterThreeRequestsNobodyAnswered()


    /**
     * Only Dependabot and Depfu pull requests can be recreated: the
     * conflicts of anyone else's are reported, and GitHub is not asked
     * anything.
     *
     * @return void
     */
    public function testLeavesAConflictingPullRequestFromAPersonAlone(): void
    {
        $output = $this->resolveConflicts("guibranco", "dirty");
        $warnings = $this->warnings->warnings();

        $this->assertSame([], $this->github->requests());
        $this->assertSame("State: dirty - Resolve conflicts - Conflicts - Sender: guibranco ⚠️\n", $output);
        $this->assertCount(1, $warnings);
        $this->assertSame('PR {repo}#{pr} has conflicts', $warnings[0]["message"]);

    }//end testLeavesAConflictingPullRequestFromAPersonAlone()


    /**
     * A pull request without conflicts needs nothing.
     *
     * @return void
     */
    public function testDoesNothingWhenThereAreNoConflicts(): void
    {
        $output = $this->resolveConflicts("dependabot[bot]", "clean");

        $this->assertSame([], $this->github->requests());
        $this->assertSame("State: clean - Resolve conflicts - No conflicts - Sender: dependabot[bot] 🆒\n", $output);
        $this->assertSame([], $this->warnings->warnings());

    }//end testDoesNothingWhenThereAreNoConflicts()


    /**
     * Each step is traced with the repository, the pull request and the
     * delivery being processed, so all the steps of a run can be followed
     * together.
     *
     * @return void
     */
    public function testTracesTheStepWithThePullRequestBeingProcessed(): void
    {
        (new ReflectionProperty(PullRequestsHandler::class, "traceId"))->setValue($this->handler, self::DELIVERY_ID);
        (new ReflectionProperty(PullRequestsHandler::class, "repoSlug"))->setValue($this->handler, "owner/repo");
        (new ReflectionProperty(PullRequestsHandler::class, "prNumber"))->setValue($this->handler, 350);

        $this->resolveConflicts("dependabot[bot]", "clean");
        $debug = $this->warnings->entries("debug");

        $this->assertCount(1, $debug);
        $this->assertSame('PR {repo}#{pr}: no conflicts ({mergeable_state})', $debug[0]["message"]);
        $this->assertSame(["repo" => "owner/repo", "pr" => 350, "mergeable_state" => "clean"], $debug[0]["context"]);
        $this->assertSame(LogStreamSpy::PULL_REQUESTS, $debug[0]["category"]);
        $this->assertSame(self::DELIVERY_ID, $debug[0]["traceId"]);

    }//end testTracesTheStepWithThePullRequestBeingProcessed()


}//end class
