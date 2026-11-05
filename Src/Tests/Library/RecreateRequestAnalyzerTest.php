<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use DateTimeImmutable;
use GuiBranco\GStracciniBot\Library\RecreateRequestAnalyzer;
use PHPUnit\Framework\TestCase;

class RecreateRequestAnalyzerTest extends TestCase
{
    private const HEAD_SHA = "3e235d6b741b467bd5b06da1615c41b076cb1c0c";

    private const OTHER_SHA = "5ee47505d89d4f2843232dc07c981b53d67926c8";

    private const REQUESTED_AT = "2026-10-21T17:40:38Z";

    private RecreateRequestAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new RecreateRequestAnalyzer();
    }

    /**
     * A recreate request as GitHub lists it, with the reactions rollup.
     *
     * @param string   $createdAt When the request was posted.
     * @param int|null $reactions How many reactions it has; null leaves the rollup out.
     * @param string   $headSha   The head commit the request was posted for.
     */
    private function request(string $createdAt = self::REQUESTED_AT, ?int $reactions = 0, string $headSha = self::HEAD_SHA): object
    {
        $comment = [
            "body" => "<!--GStraccini:{$headSha}-->\n@dependabot recreate",
            "created_at" => $createdAt,
            "user" => (object) ["login" => "guibranco"],
        ];

        if ($reactions !== null) {
            $comment["reactions"] = (object) ["total_count" => $reactions, "+1" => $reactions];
        }

        return (object) $comment;
    }

    private function comment(string $body, string $createdAt = "2026-10-15T05:54:00Z"): object
    {
        return (object) [
            "body" => $body,
            "created_at" => $createdAt,
            "user" => (object) ["login" => "coderabbitai[bot]"],
            "reactions" => (object) ["total_count" => 0],
        ];
    }

    /**
     * The moment the requests are evaluated at, counted from REQUESTED_AT.
     */
    private function after(int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::REQUESTED_AT))->modify("+{$seconds} seconds");
    }

    public function testMarkerKeepsTheFormatOfTheRequestsAlreadyPosted(): void
    {
        $this->assertSame("<!--GStraccini:" . self::HEAD_SHA . "-->", $this->analyzer->marker(self::HEAD_SHA));
    }

    public function testNotRequestedWhenThereAreNoComments(): void
    {
        $result = $this->analyzer->analyze([], self::HEAD_SHA, $this->after(0));

        $this->assertSame(
            ["status" => RecreateRequestAnalyzer::STATUS_NOT_REQUESTED, "requests" => 0, "lastRequestedAt" => null],
            $result
        );
    }

    public function testNotRequestedWhenNoCommentCarriesTheMarkerOfTheHeadCommit(): void
    {
        $comments = [
            $this->comment("Review skipped\n\nBot user detected."),
            $this->comment("@dependabot recreate"),
            (object) ["body" => null, "created_at" => "2026-10-15T05:55:00Z"],
            // A request for a previous head commit: that recreate happened, this is a new conflict.
            $this->request("2026-10-15T06:00:00Z", 1, self::OTHER_SHA),
        ];

        $result = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->after(86400));

        $this->assertSame(RecreateRequestAnalyzer::STATUS_NOT_REQUESTED, $result["status"]);
        $this->assertSame(0, $result["requests"]);
    }

    public function testAnyReactionCountsWithoutAWayToTellWhoReacted(): void
    {
        $result = $this->analyzer->analyze([$this->request(self::REQUESTED_AT, 1)], self::HEAD_SHA, $this->after(5));

        $this->assertSame(
            ["status" => RecreateRequestAnalyzer::STATUS_ACKNOWLEDGED, "requests" => 1, "lastRequestedAt" => self::REQUESTED_AT],
            $result
        );
    }

    public function testAnAcknowledgedRequestIsNeverPostedAgainHoweverOldItGets(): void
    {
        $result = $this->analyzer->analyze([$this->request(self::REQUESTED_AT, 1)], self::HEAD_SHA, $this->after(30 * 86400));

        $this->assertSame(RecreateRequestAnalyzer::STATUS_ACKNOWLEDGED, $result["status"]);
    }

    public function testWaitingWhileTheRequestIsRecentAndHasNoReactionYet(): void
    {
        $justPosted = $this->analyzer->analyze([$this->request()], self::HEAD_SHA, $this->after(2));
        $lastSecond = $this->analyzer->analyze(
            [$this->request()],
            self::HEAD_SHA,
            $this->after(RecreateRequestAnalyzer::ACKNOWLEDGEMENT_TIMEOUT_SECONDS - 1)
        );

        $this->assertSame(RecreateRequestAnalyzer::STATUS_WAITING, $justPosted["status"]);
        $this->assertSame(RecreateRequestAnalyzer::STATUS_WAITING, $lastSecond["status"]);
        $this->assertSame(1, $lastSecond["requests"]);
    }

    public function testUnacknowledgedOnceTheRequestTimedOutWithoutAnyReaction(): void
    {
        $result = $this->analyzer->analyze(
            [$this->request()],
            self::HEAD_SHA,
            $this->after(RecreateRequestAnalyzer::ACKNOWLEDGEMENT_TIMEOUT_SECONDS)
        );

        $this->assertSame(
            ["status" => RecreateRequestAnalyzer::STATUS_UNACKNOWLEDGED, "requests" => 1, "lastRequestedAt" => self::REQUESTED_AT],
            $result
        );
    }

    /**
     * guibranco/grimoire-ui#350: requested on 2026-10-21, never reacted to, and
     * still sitting there with the same head commit two weeks later.
     */
    public function testUnacknowledgedForARequestLeftWithoutReactionForTwoWeeks(): void
    {
        $comments = [
            $this->comment("### Labels\n\nThe following labels could not be found: `dependencies`."),
            $this->comment("Review skipped\n\nBot user detected."),
            $this->request(),
        ];

        $result = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-11-05T16:58:00Z"));

        $this->assertSame(RecreateRequestAnalyzer::STATUS_UNACKNOWLEDGED, $result["status"]);
        $this->assertSame(1, $result["requests"]);
    }

    public function testTheTimeoutCountsFromTheLastRequest(): void
    {
        $comments = [
            $this->request(self::REQUESTED_AT),
            $this->request("2026-10-22T08:00:00Z"),
        ];

        $waiting = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-10-22T08:30:00Z"));
        $timedOut = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-10-22T09:00:00Z"));

        $this->assertSame(
            ["status" => RecreateRequestAnalyzer::STATUS_WAITING, "requests" => 2, "lastRequestedAt" => "2026-10-22T08:00:00Z"],
            $waiting
        );
        $this->assertSame(RecreateRequestAnalyzer::STATUS_UNACKNOWLEDGED, $timedOut["status"]);
        $this->assertSame(2, $timedOut["requests"]);
    }

    public function testTheLastRequestIsFoundWhateverTheOrderOfTheComments(): void
    {
        $comments = [
            $this->request("2026-10-22T08:00:00Z"),
            $this->request(self::REQUESTED_AT),
        ];

        $result = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-10-22T08:30:00Z"));

        $this->assertSame("2026-10-22T08:00:00Z", $result["lastRequestedAt"]);
        $this->assertSame(RecreateRequestAnalyzer::STATUS_WAITING, $result["status"]);
    }

    public function testGivesUpAfterTheMaximumNumberOfUnacknowledgedRequests(): void
    {
        $comments = [];
        for ($request = 0; $request < RecreateRequestAnalyzer::MAX_REQUESTS; $request++) {
            $comments[] = $this->request("2026-10-2" . ($request + 1) . "T17:40:38Z");
        }

        $result = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-11-05T16:58:00Z"));

        $this->assertSame(RecreateRequestAnalyzer::STATUS_GAVE_UP, $result["status"]);
        $this->assertSame(RecreateRequestAnalyzer::MAX_REQUESTS, $result["requests"]);
    }

    public function testStillWaitsForTheLastAllowedRequestBeforeGivingUp(): void
    {
        $comments = [];
        for ($request = 0; $request < RecreateRequestAnalyzer::MAX_REQUESTS; $request++) {
            $comments[] = $this->request("2026-10-2" . ($request + 1) . "T17:40:38Z");
        }

        $result = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-10-23T17:45:00Z"));

        $this->assertSame(RecreateRequestAnalyzer::STATUS_WAITING, $result["status"]);
    }

    public function testAcknowledgedWhenAnyRequestForTheHeadCommitHasAReaction(): void
    {
        $comments = [
            $this->request(self::REQUESTED_AT, 0),
            $this->request("2026-10-22T08:00:00Z", 1),
            $this->request("2026-10-23T08:00:00Z", 0),
        ];

        $result = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-11-05T16:58:00Z"));

        $this->assertSame(RecreateRequestAnalyzer::STATUS_ACKNOWLEDGED, $result["status"]);
        $this->assertSame(3, $result["requests"]);
    }

    public function testAReactionOnARequestForAnotherHeadCommitDoesNotCount(): void
    {
        $comments = [
            $this->request("2026-10-15T06:00:00Z", 1, self::OTHER_SHA),
            $this->request(),
        ];

        $result = $this->analyzer->analyze($comments, self::HEAD_SHA, new DateTimeImmutable("2026-11-05T16:58:00Z"));

        $this->assertSame(RecreateRequestAnalyzer::STATUS_UNACKNOWLEDGED, $result["status"]);
        $this->assertSame(1, $result["requests"]);
    }

    public function testAReactionFromSomeoneElseIsNotTheAcknowledgement(): void
    {
        $nobodyFromTheBot = static fn(object $comment): bool => false;
        $comments = [$this->request(self::REQUESTED_AT, 1)];

        $waiting = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->after(5), $nobodyFromTheBot);
        $timedOut = $this->analyzer->analyze($comments, self::HEAD_SHA, $this->after(86400), $nobodyFromTheBot);

        $this->assertSame(RecreateRequestAnalyzer::STATUS_WAITING, $waiting["status"]);
        $this->assertSame(RecreateRequestAnalyzer::STATUS_UNACKNOWLEDGED, $timedOut["status"]);
    }

    public function testAcknowledgedWhenTheDependencyBotIsAmongThoseWhoReacted(): void
    {
        $result = $this->analyzer->analyze(
            [$this->request(self::REQUESTED_AT, 2)],
            self::HEAD_SHA,
            $this->after(86400),
            static fn(object $comment): bool => true
        );

        $this->assertSame(RecreateRequestAnalyzer::STATUS_ACKNOWLEDGED, $result["status"]);
    }

    public function testWhoReactedIsOnlyAskedAboutRequestsThatHaveReactions(): void
    {
        $withoutReactions = $this->request(self::REQUESTED_AT, 0);
        $withReactions = $this->request("2026-10-22T08:00:00Z", 1);
        $otherComment = $this->comment("Quality Gate passed");
        $otherComment->reactions->total_count = 3;
        $comments = [
            $withoutReactions,
            $otherComment,
            $this->request("2026-10-15T06:00:00Z", 1, self::OTHER_SHA),
            $withReactions,
        ];

        $asked = [];
        $result = $this->analyzer->analyze(
            $comments,
            self::HEAD_SHA,
            new DateTimeImmutable("2026-11-05T16:58:00Z"),
            static function (object $comment) use (&$asked): bool {
                $asked[] = $comment;
                return false;
            }
        );

        $this->assertSame([$withReactions], $asked);
        $this->assertSame(RecreateRequestAnalyzer::STATUS_UNACKNOWLEDGED, $result["status"]);
        $this->assertSame(2, $result["requests"]);
    }

    public function testWhoReactedIsNotAskedAgainOnceARequestIsAcknowledged(): void
    {
        $comments = [
            $this->request(self::REQUESTED_AT, 1),
            $this->request("2026-10-22T08:00:00Z", 1),
        ];

        $asked = 0;
        $result = $this->analyzer->analyze(
            $comments,
            self::HEAD_SHA,
            new DateTimeImmutable("2026-11-05T16:58:00Z"),
            static function (object $comment) use (&$asked): bool {
                $asked++;
                return true;
            }
        );

        $this->assertSame(1, $asked);
        $this->assertSame(RecreateRequestAnalyzer::STATUS_ACKNOWLEDGED, $result["status"]);
        $this->assertSame(2, $result["requests"]);
    }

    public function testARequestWithoutTheReactionsRollupIsTakenAsAcknowledged(): void
    {
        $result = $this->analyzer->analyze(
            [$this->request(self::REQUESTED_AT, null)],
            self::HEAD_SHA,
            new DateTimeImmutable("2026-11-05T16:58:00Z")
        );

        $this->assertSame(RecreateRequestAnalyzer::STATUS_ACKNOWLEDGED, $result["status"]);
    }

    public function testARequestWithoutAReadableDateNeverTimesOut(): void
    {
        $unreadable = $this->request("not a date");
        $missing = $this->request();
        unset($missing->created_at);

        $now = new DateTimeImmutable("2026-11-05T16:58:00Z");

        $this->assertSame(RecreateRequestAnalyzer::STATUS_WAITING, $this->analyzer->analyze([$unreadable], self::HEAD_SHA, $now)["status"]);
        $this->assertSame(
            ["status" => RecreateRequestAnalyzer::STATUS_WAITING, "requests" => 1, "lastRequestedAt" => null],
            $this->analyzer->analyze([$missing], self::HEAD_SHA, $now)
        );
    }
}
