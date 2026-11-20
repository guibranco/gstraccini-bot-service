<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\PendingConfirmation;
use GuiBranco\GStracciniBot\Library\PendingConfirmationTracker;
use PHPUnit\Framework\TestCase;

/**
 * How PendingConfirmationTracker records and closes out confirmations.
 */
class PendingConfirmationTrackerTest extends TestCase
{
    private InMemoryPendingConfirmationStore $store;
    private PendingConfirmationTracker $tracker;

    protected function setUp(): void
    {
        $this->store = new InMemoryPendingConfirmationStore();
        $this->tracker = new PendingConfirmationTracker($this->store);
    }

    public function testRecordsTheCommentGitHubReturned(): void
    {
        $comment = (object) ["id" => 42, "html_url" => "https://github.com/o/r/pull/3#issuecomment-42"];

        $this->tracker->record(PendingConfirmation::KIND_VERSION_BUMP, "o", "r", 7, 3, $comment, "feat: x", "dev");

        $this->assertCount(1, $this->store->recorded);
        $recorded = $this->store->recorded[0];
        $this->assertSame(42, $recorded->commentId);
        $this->assertSame("https://github.com/o/r/pull/3#issuecomment-42", $recorded->url);
        $this->assertSame(7, $recorded->installationId);
        $this->assertSame(3, $recorded->pullRequestNumber);
        $this->assertSame("dev", $recorded->author);
    }

    public function testDoesNotRecordWithoutACommentId(): void
    {
        $this->tracker->record(PendingConfirmation::KIND_VERSION_BUMP, "o", "r", 7, 3, null);
        $this->tracker->record(PendingConfirmation::KIND_VERSION_BUMP, "o", "r", 7, 3, (object) []);

        $this->assertSame([], $this->store->calls);
    }

    public function testClosedPullRequestResolutions(): void
    {
        $this->tracker->cancelForClosedPullRequest("o", "r", 3, true);
        $this->tracker->cancelForClosedPullRequest("o", "r", 4, false);

        $this->assertSame(["cancelForPullRequest o/r#3 merged", "cancelForPullRequest o/r#4 closed"], $this->store->calls);
    }

    public function testSupersedeAndResolve(): void
    {
        $this->tracker->supersede("o", "r", 3, PendingConfirmation::KIND_VERSION_BUMP);
        $this->tracker->resolveByComment("o", "r", 3, 42, "minor");

        $this->assertSame([
            "resolveForPullRequest o/r#3 version_bump superseded",
            "resolveByComment 42 minor",
        ], $this->store->calls);
    }

    public function testDatabaseFailuresAreSwallowed(): void
    {
        $this->store->databaseDown = true;

        $this->tracker->record(PendingConfirmation::KIND_VERSION_BUMP, "o", "r", 7, 3, (object) ["id" => 1]);
        $this->tracker->resolveByComment("o", "r", 3, 1, "none");
        $this->tracker->supersede("o", "r", 3, PendingConfirmation::KIND_VERSION_BUMP);
        $this->tracker->cancelForClosedPullRequest("o", "r", 3, true);

        $this->assertSame([], $this->store->calls, "Nothing reached the store, and nothing was thrown.");
    }
}
