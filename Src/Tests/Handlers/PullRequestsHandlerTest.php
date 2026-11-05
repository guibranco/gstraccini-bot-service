<?php

namespace GuiBranco\GStracciniBot\Handlers;

use GuiBranco\GStracciniBot\Tests\Handlers\PullRequestsHandlerTest;
use GuiBranco\Pancake\Response;

/**
 * Test-only override of the global doRequestGitHub() used by the Handlers namespace.
 * PHP falls back to the global function only when no same-namespaced one exists, so
 * this shadows the real (network-calling) implementation from Src/lib/github.php for
 * every call made from within GuiBranco\GStracciniBot\Handlers during these tests.
 */
function doRequestGitHub(string $token, string $url, mixed $data, string $method, string $accept = "application/vnd.github+json"): Response
{
    return PullRequestsHandlerTest::handleFakeRequest($url, $data, $method);
}

namespace GuiBranco\GStracciniBot\Tests\Handlers;

use GuiBranco\GStracciniBot\Handlers\PullRequestsHandler;
use GuiBranco\GStracciniBot\Library\VersionBumpCommentBuilder;
use GuiBranco\Pancake\RequestException;
use GuiBranco\Pancake\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PullRequestsHandlerTest extends TestCase
{
    private const HEAD_SHA = "3e235d6b741b467bd5b06da1615c41b076cb1c0c";

    private const COMMENTS_URL = "repos/owner/repo/issues/1/comments";

    private const REQUEST_ID = 6322533681;

    /** @var array<string, Response> Maps a substring of a request URL to the fake response for it. */
    private static array $responses = [];

    /** @var array<int, array{url: string, data: mixed, method: string}> */
    private static array $requestLog = [];

    protected function setUp(): void
    {
        self::$responses = [];
        self::$requestLog = [];
    }

    public static function handleFakeRequest(string $url, mixed $data, string $method): Response
    {
        self::$requestLog[] = ["url" => $url, "data" => $data, "method" => $method];

        foreach (self::$responses as $urlSubstring => $response) {
            if (strpos($url, $urlSubstring) !== false) {
                return $response;
            }
        }

        return Response::success("[]", $url, [], 200);
    }

    private function invokeCheckVersionBump(array $metadata, object $pullRequestUpdated): bool
    {
        $handler = new PullRequestsHandler();
        $method = (new ReflectionClass($handler))->getMethod("checkVersionBump");
        $method->setAccessible(true);

        return $method->invoke($handler, $metadata, $pullRequestUpdated);
    }

    private function buildMetadata(): array
    {
        return [
            "token" => "fake-token",
            "pullRequestUrl" => "repos/owner/repo/pulls/1",
            "commentsUrl" => "repos/owner/repo/issues/1/comments",
        ];
    }

    private function buildFeaturePullRequest(): object
    {
        return (object) [
            "title" => "feat: add cool feature",
            "head" => (object) ["ref" => "feature/cool-thing"],
            "labels" => [],
        ];
    }

    public function testResolvedWhenNoneWasAlreadyDecided(): void
    {
        self::$responses["/commits"] = Response::success(
            json_encode([(object) ["commit" => (object) ["message" => "Add cool feature"]]]),
            "",
            [],
            200
        );

        $decidedComment = VersionBumpCommentBuilder::MARKER . "\n### Version bump needed\n\n" .
            VersionBumpCommentBuilder::COMPLETION_MARKER . "\n✅ No version bump applied.\n";
        self::$responses["/issues/1/comments"] = Response::success(
            json_encode([(object) ["body" => $decidedComment]]),
            "",
            [],
            200
        );

        $result = $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest());

        $this->assertTrue($result, "A pull request whose comment already carries the completion marker must be treated as resolved.");

        foreach (self::$requestLog as $request) {
            if ($request["method"] === "POST") {
                $this->fail("No new version bump comment should be posted once the decision (none) was already applied.");
            }
        }
    }

    public function testStillPendingWhenNoDecisionMadeYet(): void
    {
        self::$responses["/commits"] = Response::success(
            json_encode([(object) ["commit" => (object) ["message" => "Add cool feature"]]]),
            "",
            [],
            200
        );
        self::$responses["/issues/1/comments"] = Response::success(json_encode([]), "", [], 200);

        $result = $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest());

        $this->assertFalse($result, "Without a semver directive or a prior decision, the version bump must remain pending.");

        $postedComment = false;
        foreach (self::$requestLog as $request) {
            if ($request["method"] === "POST" && strpos($request["url"], "comments") !== false) {
                $postedComment = true;
                $this->assertStringContainsString(VersionBumpCommentBuilder::MARKER, $request["data"]["body"]);
            }
        }
        $this->assertTrue($postedComment, "The actionable decision comment should be posted when nothing has been decided yet.");
    }

    public function testResolvedWhenSemverDirectiveAlreadyPresent(): void
    {
        self::$responses["/commits"] = Response::success(
            json_encode([(object) ["commit" => (object) ["message" => "Apply requested minor version bump\n\n+semver: minor"]]]),
            "",
            [],
            200
        );

        $result = $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest());

        $this->assertTrue($result, "A commit carrying a +semver directive must resolve the decision without consulting comments.");

        foreach (self::$requestLog as $request) {
            $this->assertStringNotContainsString("comments", $request["url"], "Comments should not be looked up once a +semver directive is found.");
        }
    }

    /**
     * Runs resolveConflicts() with its console output captured.
     *
     * @param object $pullRequestUpdated The pull request as the GitHub API returns it.
     * @param string $sender             The login that opened the pull request.
     *
     * @return string Everything the handler echoed.
     */
    private function invokeResolveConflicts(object $pullRequestUpdated, string $sender = "dependabot[bot]"): string
    {
        $handler = new PullRequestsHandler();
        $method = (new ReflectionClass($handler))->getMethod("resolveConflicts");

        $metadata = [
            "token" => "fake-token",
            "userToken" => "fake-user-token",
            "commentsUrl" => self::COMMENTS_URL,
            "owner" => "owner",
            "repo" => "repo",
        ];
        $pullRequest = (object) ["Number" => 1, "Sender" => $sender, "DeliveryIdText" => "00000000-0000-0000-0000-000000000000"];

        ob_start();
        try {
            $method->invoke($handler, $metadata, $pullRequest, $pullRequestUpdated);
        } finally {
            $output = ob_get_clean();
        }

        return $output;
    }

    private function buildConflictingPullRequest(): object
    {
        return (object) [
            "mergeable" => false,
            "mergeable_state" => "dirty",
            "head" => (object) ["sha" => self::HEAD_SHA],
        ];
    }

    /**
     * A recreate request as the comments endpoint lists it.
     *
     * @param int $secondsAgo How long ago it was posted.
     * @param int $reactions  How many reactions its rollup counts (who reacted comes from fakeReactions()).
     * @param int $id         The comment id.
     */
    private function buildRecreateRequest(int $secondsAgo, int $reactions, int $id = self::REQUEST_ID): object
    {
        return (object) [
            "id" => $id,
            "body" => "<!--GStraccini:" . self::HEAD_SHA . "-->\n@dependabot recreate",
            "created_at" => gmdate("Y-m-d\TH:i:s\Z", time() - $secondsAgo),
            "reactions" => (object) ["total_count" => $reactions, "+1" => $reactions],
        ];
    }

    /**
     * @param array<int, object> $comments The comments the fake endpoint lists on the given page.
     */
    private function fakeComments(array $comments, int $page = 1): void
    {
        self::$responses[self::COMMENTS_URL . "?per_page=100&page={$page}"] = Response::success(json_encode($comments), "", [], 200);
    }

    /**
     * @param array<int, string> $logins Who the fake endpoint lists as having reacted to the comment.
     */
    private function fakeReactions(array $logins, int $commentId = self::REQUEST_ID): void
    {
        $reactions = array_map(
            static fn (string $login): object => (object) ["content" => "+1", "user" => (object) ["login" => $login]],
            $logins
        );

        self::$responses["issues/comments/{$commentId}/reactions"] = Response::success(json_encode($reactions), "", [], 200);
    }

    /**
     * @return array<int, string> The body of every comment the handler posted.
     */
    private function postedComments(): array
    {
        $bodies = [];
        foreach (self::$requestLog as $request) {
            if ($request["method"] === "POST" && $request["url"] === self::COMMENTS_URL) {
                $bodies[] = $request["data"]["body"];
            }
        }

        return $bodies;
    }

    /**
     * @return array<int, string> The URL of every request the handler made to a reactions endpoint.
     */
    private function reactionsRequests(): array
    {
        return array_values(array_filter(
            array_column(self::$requestLog, "url"),
            static fn (string $url): bool => str_contains($url, "/reactions")
        ));
    }

    public function testRequestsTheRecreateOfAConflictingDependabotPullRequest(): void
    {
        $this->fakeComments([(object) ["id" => 1, "body" => "Review skipped", "created_at" => "2026-10-15T05:54:00Z", "reactions" => (object) ["total_count" => 0]]]);

        $this->invokeResolveConflicts($this->buildConflictingPullRequest());

        $this->assertSame(["<!--GStraccini:" . self::HEAD_SHA . "-->\n@dependabot recreate"], $this->postedComments());
    }

    public function testRequestsTheRecreateOfAConflictingDepfuPullRequest(): void
    {
        $this->fakeComments([]);

        $this->invokeResolveConflicts($this->buildConflictingPullRequest(), "depfu[bot]");

        $this->assertSame(["<!--GStraccini:" . self::HEAD_SHA . "-->\n@depfu recreate"], $this->postedComments());
    }

    public function testDoesNotRequestAgainOnceDependabotAcknowledgedTheRequest(): void
    {
        $this->fakeComments([$this->buildRecreateRequest(14 * 86400, 1)]);
        $this->fakeReactions(["dependabot[bot]"]);

        $output = $this->invokeResolveConflicts($this->buildConflictingPullRequest());

        $this->assertSame([], $this->postedComments(), "A request the dependency bot reacted to must not be posted again.");
        $this->assertStringContainsString("Already requested to recreate", $output);
        $this->assertSame(["repos/owner/repo/issues/comments/" . self::REQUEST_ID . "/reactions?per_page=100"], $this->reactionsRequests());
    }

    public function testDoesNotRequestAgainOnceDepfuAcknowledgedTheRequest(): void
    {
        $this->fakeComments([$this->buildRecreateRequest(14 * 86400, 2)]);
        $this->fakeReactions(["guibranco", "depfu[bot]"]);

        $output = $this->invokeResolveConflicts($this->buildConflictingPullRequest(), "depfu[bot]");

        $this->assertSame([], $this->postedComments());
        $this->assertStringContainsString("Already requested to recreate", $output);
    }

    public function testDoesNotRequestAgainWhileWaitingForTheAcknowledgement(): void
    {
        $this->fakeComments([$this->buildRecreateRequest(5, 0)]);

        $output = $this->invokeResolveConflicts($this->buildConflictingPullRequest());

        $this->assertSame([], $this->postedComments(), "A request posted seconds ago may simply not have been reacted to yet.");
        $this->assertStringContainsString("not acknowledged yet", $output);
    }

    /**
     * guibranco/grimoire-ui#350: the request was posted, Dependabot never reacted
     * to it nor recreated the pull request, and the bot kept reporting it as
     * already requested for two weeks.
     */
    public function testRequestsAgainWhenTheRequestWasNeverAcknowledged(): void
    {
        $this->fakeComments([$this->buildRecreateRequest(14 * 86400, 0)]);

        $output = $this->invokeResolveConflicts($this->buildConflictingPullRequest());

        $this->assertSame(["<!--GStraccini:" . self::HEAD_SHA . "-->\n@dependabot recreate"], $this->postedComments());
        $this->assertStringContainsString("was never acknowledged, requesting again (2/3)", $output);
        $this->assertSame([], $this->reactionsRequests(), "A request without reactions needs no look at who reacted.");
    }

    public function testAReactionFromSomeoneElseIsNotTheAcknowledgement(): void
    {
        $this->fakeComments([$this->buildRecreateRequest(14 * 86400, 1)]);
        $this->fakeReactions(["guibranco"]);

        $output = $this->invokeResolveConflicts($this->buildConflictingPullRequest());

        $this->assertSame(["<!--GStraccini:" . self::HEAD_SHA . "-->\n@dependabot recreate"], $this->postedComments());
        $this->assertStringContainsString("was never acknowledged, requesting again (2/3)", $output);
    }

    public function testAReactionThatIsNotListedAnyMoreIsNotTheAcknowledgement(): void
    {
        $this->fakeComments([$this->buildRecreateRequest(14 * 86400, 1)]);
        $this->fakeReactions([]);

        $this->invokeResolveConflicts($this->buildConflictingPullRequest());

        $this->assertSame(["<!--GStraccini:" . self::HEAD_SHA . "-->\n@dependabot recreate"], $this->postedComments());
    }

    public function testTakesTheRequestAsAcknowledgedWhenTheReactionsCannotBeListed(): void
    {
        $this->fakeComments([$this->buildRecreateRequest(14 * 86400, 1)]);
        self::$responses["issues/comments/" . self::REQUEST_ID . "/reactions"] = Response::error("Bad gateway", "", 502);

        $output = $this->invokeResolveConflicts($this->buildConflictingPullRequest());

        $this->assertSame([], $this->postedComments(), "Without knowing who reacted, the request must not be posted again on a guess.");
        $this->assertStringContainsString("Already requested to recreate", $output);
    }

    public function testGivesUpAfterThreeRequestsWereNeverAcknowledged(): void
    {
        $this->fakeComments([
            $this->buildRecreateRequest(14 * 86400, 0, 101),
            $this->buildRecreateRequest(13 * 86400, 0, 102),
            $this->buildRecreateRequest(11 * 86400, 0, 103),
        ]);

        $output = $this->invokeResolveConflicts($this->buildConflictingPullRequest());

        $this->assertSame([], $this->postedComments(), "The bot must stop asking after the maximum number of unacknowledged requests.");
        $this->assertStringContainsString("Recreate requested 3 times and never acknowledged, giving up", $output);
    }

    public function testFindsARequestBeyondTheFirstPageOfComments(): void
    {
        $otherComment = (object) ["id" => 1, "body" => "Quality Gate passed", "created_at" => "2026-10-15T05:54:00Z", "reactions" => (object) ["total_count" => 0]];
        $this->fakeComments(array_fill(0, 100, $otherComment), 1);
        $this->fakeComments([$this->buildRecreateRequest(14 * 86400, 1)], 2);
        $this->fakeReactions(["dependabot[bot]"]);

        $this->invokeResolveConflicts($this->buildConflictingPullRequest());

        $this->assertSame([], $this->postedComments(), "A request listed on the second page of comments must still be found.");
        $this->assertSame(
            [self::COMMENTS_URL . "?per_page=100&page=1", self::COMMENTS_URL . "?per_page=100&page=2"],
            array_slice(array_column(self::$requestLog, "url"), 0, 2)
        );
    }

    public function testDoesNotRequestWhenTheCommentsCannotBeListed(): void
    {
        self::$responses[self::COMMENTS_URL] = Response::error("Bad gateway", self::COMMENTS_URL, 502);

        try {
            $this->invokeResolveConflicts($this->buildConflictingPullRequest());
            $this->fail("A failed comments listing must fail the item instead of posting a request on a guess.");
        } catch (RequestException) {
            $this->assertSame([], $this->postedComments());
        }
    }

    public function testLeavesAConflictingPullRequestFromAPersonAlone(): void
    {
        $output = $this->invokeResolveConflicts($this->buildConflictingPullRequest(), "guibranco");

        $this->assertSame([], self::$requestLog, "Only Dependabot and Depfu pull requests can be recreated.");
        $this->assertStringContainsString("Resolve conflicts - Conflicts", $output);
    }

    public function testDoesNothingWhenThereAreNoConflicts(): void
    {
        $pullRequestUpdated = (object) ["mergeable" => true, "mergeable_state" => "clean", "head" => (object) ["sha" => self::HEAD_SHA]];

        $output = $this->invokeResolveConflicts($pullRequestUpdated);

        $this->assertSame([], self::$requestLog);
        $this->assertStringContainsString("No conflicts", $output);
    }
}
