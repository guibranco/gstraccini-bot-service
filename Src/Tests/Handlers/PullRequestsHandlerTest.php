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
use GuiBranco\Pancake\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PullRequestsHandlerTest extends TestCase
{
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
}
