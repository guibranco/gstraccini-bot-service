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
use GuiBranco\GStracciniBot\Library\FeatureSettings;
use GuiBranco\GStracciniBot\Library\VersionBumpCommentBuilder;
use GuiBranco\Pancake\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

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

    private function invokeCheckVersionBump(array $metadata, object $pullRequestUpdated, bool $requestVersionBump = true): bool
    {
        $handler = new PullRequestsHandler(new FixedFeatureSettings($requestVersionBump));
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

    /**
     * Invokes the private PullRequestsHandler::isFromFork() method.
     *
     * @param object $pullRequestUpdated The pull request as returned by the GitHub API.
     *
     * @return bool Whether the pull request comes from a fork.
     */
    private function invokeIsFromFork(object $pullRequestUpdated): bool
    {
        $handler = new PullRequestsHandler();
        $method = (new ReflectionClass($handler))->getMethod("isFromFork");
        $method->setAccessible(true);

        return $method->invoke($handler, $pullRequestUpdated);
    }

    /**
     * Builds a repository object as returned by the GitHub API, or null when absent.
     *
     * @param string|null $fullName The repository full name (owner/name).
     *
     * @return object|null The repository object.
     */
    private function buildRepo(?string $fullName): ?object
    {
        if ($fullName === null) {
            return null;
        }

        return (object) ["full_name" => $fullName];
    }

    /**
     * Builds a pull request with the given head and base repositories.
     *
     * @param string|null $headRepo The head repository full name, null for a deleted fork.
     * @param string|null $baseRepo The base repository full name.
     *
     * @return object The pull request object.
     */
    private function buildPullRequestRepos(?string $headRepo, ?string $baseRepo): object
    {
        $pullRequest = new stdClass();
        $pullRequest->head = (object) ["repo" => $this->buildRepo($headRepo)];
        $pullRequest->base = (object) ["repo" => $this->buildRepo($baseRepo)];

        return $pullRequest;
    }

    /**
     * A pull request whose head lives in another repository comes from a fork.
     *
     * @return void
     */
    public function testIsFromForkWhenHeadRepoDiffers(): void
    {
        $this->assertTrue($this->invokeIsFromFork($this->buildPullRequestRepos("contributor/repo", "owner/repo")));
    }

    /**
     * A pull request whose fork was deleted (null head repository) still comes from a fork.
     *
     * @return void
     */
    public function testIsFromForkWhenForkWasDeleted(): void
    {
        $this->assertTrue($this->invokeIsFromFork($this->buildPullRequestRepos(null, "owner/repo")));
    }

    /**
     * A pull request between branches of the same repository (case-insensitive) is not from a fork.
     *
     * @return void
     */
    public function testIsNotFromForkWhenSameRepo(): void
    {
        $this->assertFalse($this->invokeIsFromFork($this->buildPullRequestRepos("Owner/Repo", "owner/repo")));
    }

    /**
     * Without a known base repository the pull request is not treated as coming from a fork.
     *
     * @return void
     */
    public function testIsNotFromForkWhenBaseRepoUnknown(): void
    {
        $this->assertFalse($this->invokeIsFromFork($this->buildPullRequestRepos("contributor/repo", null)));
    }

    /**
     * Requesting a version bump is opt-in: with the setting off for the repository and its
     * account, the decision is resolved without reading commits or posting a comment.
     *
     * @return void
     */
    public function testResolvedWithoutAnyRequestWhenRequestVersionBumpIsDisabled(): void
    {
        $result = $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest(), false);

        $this->assertTrue($result, "A pull request of a repository that did not opt in must be treated as resolved.");
        $this->assertSame([], self::$requestLog, "No GitHub request should be made when the feature is disabled.");
    }

    /**
     * The repository and account ids of the base repository are the ones the settings are looked up with.
     *
     * @return void
     */
    public function testLooksUpSettingsWithBaseRepositoryAndOwnerIds(): void
    {
        $settings = new FixedFeatureSettings(false);
        $handler = new PullRequestsHandler($settings);
        $method = (new ReflectionClass($handler))->getMethod("checkVersionBump");
        $method->setAccessible(true);

        $pullRequest = $this->buildFeaturePullRequest();
        $pullRequest->base = (object) ["repo" => (object) ["id" => 123, "owner" => (object) ["id" => 456]]];
        $method->invoke($handler, $this->buildMetadata(), $pullRequest);

        $this->assertSame(["456:123"], $settings->lookups);
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

/**
 * Feature settings with a fixed answer instead of the database.
 */
class FixedFeatureSettings extends FeatureSettings
{
    /**
     * The lookups made, as "accountId:repositoryId".
     *
     * @var string[]
     */
    public array $lookups = [];

    /**
     * Sets the answer of every lookup.
     *
     * @param bool $enabled Whether the version bump decision is requested.
     */
    public function __construct(private bool $enabled)
    {
    }

    /**
     * Returns the fixed answer.
     *
     * @param int $accountId    The account looked up.
     * @param int $repositoryId The repository looked up.
     *
     * @return bool The fixed answer.
     */
    public function isRequestVersionBumpEnabled(int $accountId, int $repositoryId): bool
    {
        $this->lookups[] = "{$accountId}:{$repositoryId}";
        return $this->enabled;
    }
}
