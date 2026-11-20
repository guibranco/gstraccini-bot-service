<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\InfisicalIgnoreApprovalCommentBuilder;
use GuiBranco\GStracciniBot\Library\PendingConfirmation;
use GuiBranco\GStracciniBot\Library\PendingConfirmationReminder;
use GuiBranco\GStracciniBot\Library\VersionBumpCommentBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How PendingConfirmationReminder reminds of, or closes out, the confirmations due.
 */
class PendingConfirmationReminderTest extends TestCase
{
    private const PULL_REQUEST_URL = "repos/owner/repo/pulls/1";
    private const COMMENT_URL = "repos/owner/repo/issues/comments/99";

    private FakeGitHub $github;
    private InMemoryPendingConfirmationStore $store;
    private PendingConfirmationReminder $reminder;

    protected function setUp(): void
    {
        $this->github = new FakeGitHub();
        $this->store = new InMemoryPendingConfirmationStore();
        $this->reminder = new PendingConfirmationReminder($this->store, static fn(int $installationId): string => FakeGitHub::TOKEN);
    }

    private function confirmation(string $kind = PendingConfirmation::KIND_VERSION_BUMP, int $reminderCount = 0): PendingConfirmation
    {
        return new PendingConfirmation(5, $kind, "owner", "repo", 7, 1, 99, "https://github.com/owner/repo/pull/1#issuecomment-99", "feat: thing", "stale-author", $reminderCount);
    }

    private function openPullRequest(): void
    {
        $this->github->answer(self::PULL_REQUEST_URL, json_encode(["state" => "open", "merged" => false, "user" => ["login" => "author"]]));
    }

    public function testRemindsTheAuthorOfAPendingConfirmation(): void
    {
        $this->store->due = [$this->confirmation()];
        $this->openPullRequest();
        $this->github->answer(self::COMMENT_URL, json_encode(["body" => VersionBumpCommentBuilder::MARKER, "html_url" => "https://example.test/c/99"]));

        $report = $this->reminder->run();

        $this->assertSame(1, $report["reminded"]);
        $posted = $this->github->posted();
        $this->assertCount(1, $posted);
        $this->assertStringContainsString(PendingConfirmationReminder::MARKER, $posted[0]);
        $this->assertStringContainsString("@author", $posted[0], "The current PR author is mentioned.");
        $this->assertStringContainsString("https://example.test/c/99", $posted[0]);
        $this->assertContains("POST fake-token repos/owner/repo/issues/1/comments", $this->github->requests());
        $this->assertContains("markReminded 5", $this->store->calls);
    }

    public function testCancelsWhenThePullRequestWasMerged(): void
    {
        $this->store->due = [$this->confirmation()];
        $this->github->answer(self::PULL_REQUEST_URL, json_encode(["state" => "closed", "merged" => true]));

        $report = $this->reminder->run();

        $this->assertSame(1, $report["cancelled"]);
        $this->assertContains("cancelByComment 99 merged", $this->store->calls);
        $this->assertSame([], $this->github->posted());
    }

    public function testCancelsWhenThePullRequestWasClosed(): void
    {
        $this->store->due = [$this->confirmation()];
        $this->github->answer(self::PULL_REQUEST_URL, json_encode(["state" => "closed", "merged" => false]));

        $this->reminder->run();

        $this->assertContains("cancelByComment 99 closed", $this->store->calls);
    }

    public function testCancelsWhenTheCommentWasDeleted(): void
    {
        $this->store->due = [$this->confirmation()];
        $this->openPullRequest();
        $this->github->fail(self::COMMENT_URL, 404);

        $report = $this->reminder->run();

        $this->assertSame(1, $report["cancelled"]);
        $this->assertContains("cancelByComment 99 comment_deleted", $this->store->calls);
        $this->assertSame([], $this->github->posted());
    }

    public function testResolvesWhenTheChoiceWasAlreadyApplied(): void
    {
        $this->store->due = [$this->confirmation(PendingConfirmation::KIND_INFISICAL_IGNORE)];
        $this->openPullRequest();
        $this->github->answer(self::COMMENT_URL, json_encode(["body" => "x" . InfisicalIgnoreApprovalCommentBuilder::COMPLETION_MARKER]));

        $report = $this->reminder->run();

        $this->assertSame(1, $report["resolved"]);
        $this->assertContains("resolveByComment 99 applied", $this->store->calls);
        $this->assertSame([], $this->github->posted());
    }

    public function testSkipsWhenThePullRequestCantBeLoaded(): void
    {
        $this->store->due = [$this->confirmation()];
        $this->github->fail(self::PULL_REQUEST_URL, 500);

        $report = $this->reminder->run();

        $this->assertSame(1, $report["skipped"]);
        $this->assertNotContains("markReminded 5", $this->store->calls);
    }

    public function testOneFailureDoesNotStopTheOthers(): void
    {
        $this->store->due = [$this->confirmation(), $this->confirmation()];
        $this->openPullRequest();
        $this->github->answer(self::COMMENT_URL, json_encode(["body" => ""]));
        $calls = 0;
        $reminder = new PendingConfirmationReminder($this->store, static function () use (&$calls): string {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException("token failure");
            }
            return FakeGitHub::TOKEN;
        });

        $report = $reminder->run();

        $this->assertSame(1, $report["skipped"]);
        $this->assertSame(1, $report["reminded"]);
    }

    public function testVersionBumpReminderMentionsTheCheckAndCountsReminders(): void
    {
        $body = $this->reminder->buildReminder($this->confirmation(PendingConfirmation::KIND_VERSION_BUMP, 1), "someone", "https://x.test");

        $this->assertStringContainsString("a version bump choice", $body);
        $this->assertStringContainsString("GStraccini Checks: Pull Request", $body);
        $this->assertStringContainsString("Reminder 2 of " . PendingConfirmationReminder::MAX_REMINDERS, $body);
    }

    public function testInfisicalReminderWithoutAuthorOrUrl(): void
    {
        $body = $this->reminder->buildReminder($this->confirmation(PendingConfirmation::KIND_INFISICAL_IGNORE), null, null);

        $this->assertStringStartsWith(PendingConfirmationReminder::MARKER . "\nHi, ", $body);
        $this->assertStringContainsString("`.infisicalignore`", $body);
        $this->assertStringContainsString("an earlier comment", $body);
        $this->assertStringNotContainsString("GStraccini Checks", $body);
    }
}
