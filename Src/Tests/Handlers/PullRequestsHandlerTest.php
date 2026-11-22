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
use GuiBranco\GStracciniBot\Library\PendingConfirmation;
use GuiBranco\GStracciniBot\Library\PendingConfirmationTracker;
use GuiBranco\GStracciniBot\Library\VersionBumpCommentBuilder;
use GuiBranco\GStracciniBot\Tests\Library\InMemoryPendingConfirmationStore;
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

    /**
     * Where the handler records the pending confirmations.
     *
     * @var InMemoryPendingConfirmationStore
     */
    private InMemoryPendingConfirmationStore $pendingConfirmations;

    protected function setUp(): void
    {
        self::$responses = [];
        self::$requestLog = [];
        $this->pendingConfirmations = new InMemoryPendingConfirmationStore();
    }

    public static function handleFakeRequest(string $url, mixed $data, string $method): Response
    {
        self::$requestLog[] = ["url" => $url, "data" => $data, "method" => $method];

        // A key may be prefixed with "METHOD " to answer that method only.
        foreach (self::$responses as $urlSubstring => $response) {
            $parts = explode(" ", $urlSubstring, 2);
            if (count($parts) === 2 && $parts[0] !== $method) {
                continue;
            }
            if (strpos($url, end($parts)) !== false) {
                return $response;
            }
        }

        return Response::success("[]", $url, [], 200);
    }

    /**
     * Invokes the private PullRequestsHandler::checkVersionBump() method.
     *
     * @param array           $metadata           Metadata for the GitHub API requests.
     * @param object          $pullRequestUpdated The pull request as returned by the GitHub API.
     * @param FeatureSettings $settings           The feature settings.
     *
     * @return bool Whether the version bump decision is resolved.
     */
    private function invokeCheckVersionBump(array $metadata, object $pullRequestUpdated, FeatureSettings $settings): bool
    {
        $handler = new PullRequestsHandler($settings, new PendingConfirmationTracker($this->pendingConfirmations));
        $method = (new ReflectionClass($handler))->getMethod("checkVersionBump");
        $method->setAccessible(true);

        return $method->invoke($handler, $metadata, $pullRequestUpdated);
    }

    private function buildMetadata(): array
    {
        $metadata                   = [
            "token" => "fake-token",
            "pullRequestUrl" => "repos/owner/repo/pulls/1",
            "commentsUrl" => "repos/owner/repo/issues/1/comments",
        ];
        $metadata['owner']          = 'owner';
        $metadata['repo']           = 'repo';
        $metadata['installationId'] = 7;

        return $metadata;
    }

    private function buildFeaturePullRequest(): object
    {
        $pullRequest              = (object) [
            "title" => "feat: add cool feature",
            "head" => (object) ["ref" => "feature/cool-thing"],
            "labels" => [],
        ];
        $pullRequest->number      = 1;
        $pullRequest->user        = new stdClass();
        $pullRequest->user->login = 'author';

        return $pullRequest;
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

        $result = $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest(), new FixedFeatureSettings(true));

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

        $result = $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest(), new FixedFeatureSettings(true));

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
     * A posted version bump decision comment is recorded as a pending confirmation.
     *
     * @return void
     */
    public function testPostedDecisionIsRecordedAsPendingConfirmation(): void
    {
        $created           = new stdClass();
        $created->id       = 555;
        $created->html_url = 'https://github.com/owner/repo/pull/1#issuecomment-555';
        $created->body     = '';
        self::$responses['/commits']                = new Response(true, json_encode([]), '', 200, '', []);
        self::$responses['POST /issues/1/comments'] = new Response(true, json_encode($created), '', 201, '', []);

        $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest(), new FixedFeatureSettings(true));

        $this->assertCount(1, $this->pendingConfirmations->recorded);
        $recorded = $this->pendingConfirmations->recorded[0];
        $this->assertSame(PendingConfirmation::KIND_VERSION_BUMP, $recorded->kind);
        $this->assertSame(555, $recorded->commentId);
        $this->assertSame(7, $recorded->installationId);
        $this->assertSame('author', $recorded->author);

    }//end testPostedDecisionIsRecordedAsPendingConfirmation()


    /**
     * A decision comment posted before the tracking existed is recorded when found.
     *
     * @return void
     */
    public function testExistingDecisionCommentIsBackfilled(): void
    {
        $existing                    = new stdClass();
        $existing->id                = 321;
        $existing->html_url          = 'u';
        $existing->body              = VersionBumpCommentBuilder::MARKER.PHP_EOL.'### Version bump needed';
        self::$responses['/commits'] = new Response(true, json_encode([]), '', 200, '', []);
        self::$responses['/issues/1/comments'] = new Response(true, json_encode([$existing]), '', 200, '', []);

        $result = $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest(), new FixedFeatureSettings(true));

        $this->assertFalse($result);
        $this->assertSame(['record version_bump 321'], $this->pendingConfirmations->calls);

    }//end testExistingDecisionCommentIsBackfilled()


    /**
     * A pushed `+semver` directive supersedes the pending decision.
     *
     * @return void
     */
    public function testSemverDirectiveSupersedesAPendingDecision(): void
    {
        $commit                  = new stdClass();
        $commit->commit          = new stdClass();
        $commit->commit->message = 'bump'.PHP_EOL.PHP_EOL.'+semver: minor';
        self::$responses['/commits'] = new Response(true, json_encode([$commit]), '', 200, '', []);

        $result = $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest(), new FixedFeatureSettings(true));

        $this->assertTrue($result);
        $this->assertSame(['resolveForPullRequest owner/repo#1 version_bump superseded'], $this->pendingConfirmations->calls);

    }//end testSemverDirectiveSupersedesAPendingDecision()


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
    public function testSkipsWhenVersionBumpDisabled(): void
    {
        $result = $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest(), new FixedFeatureSettings(false));

        $this->assertTrue($result, "A pull request of a repository that did not opt in must be treated as resolved.");
        $this->assertSame([], self::$requestLog, "No GitHub request should be made when the feature is disabled.");
        $this->assertSame(['resolveForPullRequest owner/repo#1 version_bump superseded'], $this->pendingConfirmations->calls, 'A decision still pending when the feature was turned off is no longer awaited.');

    }//end testSkipsWhenVersionBumpDisabled()


    /**
     * The repository and account ids of the base repository are the ones the settings are looked up with.
     *
     * @return void
     */
    public function testLooksUpBaseRepositoryAndOwner(): void
    {
        $settings = new FixedFeatureSettings(false);
        $handler = new PullRequestsHandler($settings);
        $method = (new ReflectionClass($handler))->getMethod("checkVersionBump");
        $method->setAccessible(true);

        $pullRequest = $this->buildFeaturePullRequest();
        $repo = new stdClass();
        $repo->id = 123;
        $repo->owner = (object) ["id" => 456];
        $pullRequest->base = (object) ["repo" => $repo];
        $method->invoke($handler, $this->buildMetadata(), $pullRequest);

        $this->assertSame(["456:123"], $settings->lookups);

    }//end testLooksUpBaseRepositoryAndOwner()


    public function testResolvedWhenSemverDirectiveAlreadyPresent(): void
    {
        self::$responses["/commits"] = Response::success(
            json_encode([(object) ["commit" => (object) ["message" => "Apply requested minor version bump\n\n+semver: minor"]]]),
            "",
            [],
            200
        );

        $result = $this->invokeCheckVersionBump($this->buildMetadata(), $this->buildFeaturePullRequest(), new FixedFeatureSettings(true));

        $this->assertTrue($result, "A commit carrying a +semver directive must resolve the decision without consulting comments.");

        foreach (self::$requestLog as $request) {
            $this->assertStringNotContainsString("comments", $request["url"], "Comments should not be looked up once a +semver directive is found.");
        }
    }

    /**
     * The bot's approval on a later page of reviews must still be found.
     *
     * @return void
     */
    public function testGetReviewsReadsAllPages(): void
    {
        $otherReviews = array_fill(0, 100, ["user" => ["login" => "some-review-bot[bot]"]]);
        self::$responses["/reviews?per_page=100&page=1"] = new Response(true, json_encode($otherReviews), "", 200, "", []);
        self::$responses["/reviews?per_page=100&page=2"] = new Response(true, json_encode([["user" => ["login" => "gstraccini[bot]"]]]), "", 200, "", []);

        $handler = new PullRequestsHandler(null, new PendingConfirmationTracker($this->pendingConfirmations));
        $method = (new ReflectionClass($handler))->getMethod("getReviews");
        $method->setAccessible(true);

        $reviews = $method->invoke($handler, ["token" => "fake-token", "reviewsUrl" => "repos/owner/repo/pulls/1/reviews"]);
        $logins = array_map(static fn ($review) => $review->user->login, $reviews);

        $this->assertCount(101, $logins);
        $this->assertContains("gstraccini[bot]", $logins);
    }

    /**
     * A page of reviews that can't be read must throw, not return a partial list.
     *
     * @return void
     */
    public function testGetReviewsThrowsOnError(): void
    {
        self::$responses["/reviews"] = new Response(false, null, "boom", 500, "", null);

        $handler = new PullRequestsHandler(null, new PendingConfirmationTracker($this->pendingConfirmations));
        $method = (new ReflectionClass($handler))->getMethod("getReviews");
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $method->invoke($handler, ["token" => "fake-token", "reviewsUrl" => "repos/owner/repo/pulls/1/reviews"]);
    }

    /**
     * Builds a review as returned by the GitHub API.
     *
     * @param string $login    The reviewer login.
     * @param string $state    The review state.
     * @param string $commitId The reviewed commit.
     *
     * @return object The review.
     */
    private static function review(string $login, string $state, string $commitId): object
    {
        $type = str_ends_with($login, "[bot]") ? "Bot" : "User";

        return json_decode(json_encode(["user" => ["login" => $login, "type" => $type], "state" => $state, "commit_id" => $commitId]));
    }

    /**
     * Invokes isApprovedOnHead() with a collaborator list of the owner and the bot's user account.
     *
     * @param array $reviews The reviews.
     *
     * @return bool The result.
     */
    private function approvedOnHead(array $reviews): bool
    {
        $handler = new PullRequestsHandler(null, new PendingConfirmationTracker($this->pendingConfirmations));
        $method = (new ReflectionClass($handler))->getMethod("isApprovedOnHead");
        $method->setAccessible(true);

        return $method->invoke($handler, $reviews, ["gstraccini", "guibranco", "renovate[bot]"], "head", "contributor");
    }

    /**
     * One collaborator approving the head is enough, even when other collaborators never review.
     *
     * @return void
     */
    public function testApprovedOnHeadByOneCollaborator(): void
    {
        $reviews   = [];
        $reviews[] = self::review("gstraccini[bot]", "APPROVED", "head");
        $reviews[] = self::review("guibranco", "APPROVED", "head");
        $reviews[] = self::review("guibranco", "COMMENTED", "head");

        $this->assertTrue($this->approvedOnHead($reviews));
    }

    /**
     * Code pushed after the approval means the pull request needs triage again.
     *
     * @return void
     */
    public function testNotApprovedOnOlderCommit(): void
    {
        $this->assertFalse($this->approvedOnHead([self::review("guibranco", "APPROVED", "older")]));
    }

    /**
     * A later "changes requested" or a dismissed approval revokes the approval.
     *
     * @return void
     */
    public function testNotApprovedWhenRevoked(): void
    {
        $revoked   = [];
        $revoked[] = self::review("guibranco", "APPROVED", "head");
        $revoked[] = self::review("guibranco", "CHANGES_REQUESTED", "head");
        $this->assertFalse($this->approvedOnHead($revoked));

        $dismissed   = [];
        $dismissed[] = self::review("guibranco", "APPROVED", "head");
        $dismissed[] = self::review("guibranco", "DISMISSED", "head");
        $this->assertFalse($this->approvedOnHead($dismissed));
    }

    /**
     * Bots, even listed as collaborators, and non-collaborators don't triage a pull request.
     *
     * @return void
     */
    public function testNotApprovedByBotOrOutsider(): void
    {
        $this->assertFalse($this->approvedOnHead([self::review("renovate[bot]", "APPROVED", "head")]));
        $this->assertFalse($this->approvedOnHead([self::review("someone-else", "APPROVED", "head")]));
    }

    /**
     * The label is removed when the head is approved, and kept otherwise.
     *
     * @return void
     */
    public function testRemoveAwaitingTriageIfApproved(): void
    {
        self::$responses["DELETE /labels/"] = new Response(true, "[]", "", 200, "", []);
        $handler = new PullRequestsHandler(null, new PendingConfirmationTracker($this->pendingConfirmations));
        $method = (new ReflectionClass($handler))->getMethod("removeAwaitingTriageIfApproved");
        $method->setAccessible(true);

        $metadata = [
            "token"          => "fake-token",
            "pullRequestUrl" => "repos/owner/repo/issues/1",
            "owner"          => "owner",
            "repo"           => "repo",
        ];
        $pullRequest = json_decode(json_encode(["number" => 1, "labels" => [["name" => "🚦 awaiting triage"]]]));

        $method->invoke($handler, $metadata, $pullRequest, false);
        $this->assertCount(0, self::$requestLog, "The label must stay until the head commit is approved.");

        $method->invoke($handler, $metadata, $pullRequest, true);
        $this->assertCount(1, self::$requestLog);
        $this->assertSame("DELETE", self::$requestLog[0]["method"]);
        $this->assertStringContainsString("/labels/🚦%20awaiting%20triage", self::$requestLog[0]["url"]);
    }
}

/**
 * Feature settings with a fixed answer instead of the database.
 */
class FixedFeatureSettings extends FeatureSettings
{
    /**
     * How a lookup is recorded: the account id, then the repository id.
     */
    public const LOOKUP_FORMAT = '%d:%d';

    /**
     * The lookups made, as "accountId:repositoryId".
     *
     * @var string[]
     */
    public array $lookups = [];

    /**
     * The answer of every lookup.
     *
     * @var boolean
     */
    private bool $enabled;


    /**
     * Sets the answer of every lookup.
     *
     * @param bool $enabled Whether the version bump decision is requested.
     */
    public function __construct(bool $enabled)
    {
        $this->enabled = $enabled;

    }//end __construct()


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
        $this->lookups[] = sprintf(self::LOOKUP_FORMAT, $accountId, $repositoryId);
        return $this->enabled;

    }//end isRequestVersionBumpEnabled()

}
