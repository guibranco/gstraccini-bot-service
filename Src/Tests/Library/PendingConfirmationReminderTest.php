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
    private const PULL_REQUEST_URL = 'repos/owner/repo/pulls/1';
    private const COMMENT_URL      = 'repos/owner/repo/issues/comments/99';

    /**
     * The github.
     *
     * @var FakeGitHub
     */
    private FakeGitHub $github;

    /**
     * The store.
     *
     * @var InMemoryPendingConfirmationStore
     */
    private InMemoryPendingConfirmationStore $store;

    /**
     * The reminder.
     *
     * @var PendingConfirmationReminder
     */
    private PendingConfirmationReminder $reminder;


    /**
     * Sets up the fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->github   = new FakeGitHub();
        $this->store    = new InMemoryPendingConfirmationStore();
        $this->reminder = new PendingConfirmationReminder($this->store, static fn(int $installationId): string => FakeGitHub::TOKEN);

    }//end setUp()


    /**
     * Confirmation.
     *
     * @param  string  $kind          The kind.
     * @param  integer $reminderCount The reminderCount.
     * @return PendingConfirmation
     */
    private function confirmation(string $kind=PendingConfirmation::KIND_VERSION_BUMP, int $reminderCount=0): PendingConfirmation
    {
        return new PendingConfirmation(5, $kind, 'owner', 'repo', 7, 1, 99, 'https://github.com/owner/repo/pull/1#issuecomment-99', 'feat: thing', 'stale-author', $reminderCount);

    }//end confirmation()


    /**
     * Open pull request.
     *
     * @return void
     */
    private function openPullRequest(): void
    {
        $this->github->answer(self::PULL_REQUEST_URL, json_encode(['state' => 'open', 'merged' => false, 'user' => ['login' => 'author']]));

    }//end openPullRequest()


    /**
     * Reminds the author of a pending confirmation.
     *
     * @return void
     */
    public function testRemindsTheAuthorOfAPendingConfirmation(): void
    {
        $this->store->due = [$this->confirmation()];
        $this->openPullRequest();
        $this->github->answer(self::COMMENT_URL, json_encode(['body' => VersionBumpCommentBuilder::MARKER, 'html_url' => 'https://example.test/c/99']));

        $report = $this->reminder->run();

        $this->assertSame(1, $report['reminded']);
        $posted = $this->github->posted();
        $this->assertCount(1, $posted);
        $this->assertStringContainsString(PendingConfirmationReminder::MARKER, $posted[0]);
        $this->assertStringContainsString('@author', $posted[0], 'The current PR author is mentioned.');
        $this->assertStringContainsString('https://example.test/c/99', $posted[0]);
        $this->assertContains('POST fake-token repos/owner/repo/issues/1/comments', $this->github->requests());
        $this->assertContains('markReminded 5', $this->store->calls);

    }//end testRemindsTheAuthorOfAPendingConfirmation()


    /**
     * Cancels when the pull request was merged.
     *
     * @return void
     */
    public function testCancelsWhenThePullRequestWasMerged(): void
    {
        $this->store->due = [$this->confirmation()];
        $this->github->answer(self::PULL_REQUEST_URL, json_encode(['state' => 'closed', 'merged' => true]));

        $report = $this->reminder->run();

        $this->assertSame(1, $report['cancelled']);
        $this->assertContains('cancelByComment 99 merged', $this->store->calls);
        $this->assertSame([], $this->github->posted());

    }//end testCancelsWhenThePullRequestWasMerged()


    /**
     * Cancels when the pull request was closed.
     *
     * @return void
     */
    public function testCancelsWhenThePullRequestWasClosed(): void
    {
        $this->store->due = [$this->confirmation()];
        $this->github->answer(self::PULL_REQUEST_URL, json_encode(['state' => 'closed', 'merged' => false]));

        $this->reminder->run();

        $this->assertContains('cancelByComment 99 closed', $this->store->calls);

    }//end testCancelsWhenThePullRequestWasClosed()


    /**
     * Cancels when the comment was deleted.
     *
     * @return void
     */
    public function testCancelsWhenTheCommentWasDeleted(): void
    {
        $this->store->due = [$this->confirmation()];
        $this->openPullRequest();
        $this->github->fail(self::COMMENT_URL, 404);

        $report = $this->reminder->run();

        $this->assertSame(1, $report['cancelled']);
        $this->assertContains('cancelByComment 99 comment_deleted', $this->store->calls);
        $this->assertSame([], $this->github->posted());

    }//end testCancelsWhenTheCommentWasDeleted()


    /**
     * Resolves when the choice was already applied.
     *
     * @return void
     */
    public function testResolvesWhenTheChoiceWasAlreadyApplied(): void
    {
        $this->store->due = [$this->confirmation(PendingConfirmation::KIND_INFISICAL_IGNORE)];
        $this->openPullRequest();
        $this->github->answer(self::COMMENT_URL, json_encode(['body' => 'x'.InfisicalIgnoreApprovalCommentBuilder::COMPLETION_MARKER]));

        $report = $this->reminder->run();

        $this->assertSame(1, $report['resolved']);
        $this->assertContains('resolveByComment 99 applied', $this->store->calls);
        $this->assertSame([], $this->github->posted());

    }//end testResolvesWhenTheChoiceWasAlreadyApplied()


    /**
     * Skips when the pull request cant be loaded.
     *
     * @return void
     */
    public function testSkipsWhenThePullRequestCantBeLoaded(): void
    {
        $this->store->due = [$this->confirmation()];
        $this->github->fail(self::PULL_REQUEST_URL, 500);

        $report = $this->reminder->run();

        $this->assertSame(1, $report['skipped']);
        $this->assertNotContains('markReminded 5', $this->store->calls);

    }//end testSkipsWhenThePullRequestCantBeLoaded()


    /**
     * One failure does not stop the others.
     *
     * @return void
     */
    public function testOneFailureDoesNotStopTheOthers(): void
    {
        $this->store->due = [
            $this->confirmation(),
            $this->confirmation(),
        ];
        $this->openPullRequest();
        $this->github->answer(self::COMMENT_URL, json_encode(['body' => '']));
        $calls    = 0;
        $reminder = new PendingConfirmationReminder(
            $this->store,
            static function () use (&$calls): string {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('token failure');
                }

                return FakeGitHub::TOKEN;
            }
        );

        $report = $reminder->run();

        $this->assertSame(1, $report['skipped']);
        $this->assertSame(1, $report['reminded']);

    }//end testOneFailureDoesNotStopTheOthers()


    /**
     * Version bump reminder mentions the check and counts reminders.
     *
     * @return void
     */
    public function testVersionBumpReminderMentionsTheCheckAndCountsReminders(): void
    {
        $body = $this->reminder->buildReminder($this->confirmation(PendingConfirmation::KIND_VERSION_BUMP, 1), 'someone', 'https://x.test');

        $this->assertStringContainsString('a version bump choice', $body);
        $this->assertStringContainsString('GStraccini Checks: Pull Request', $body);
        $this->assertStringContainsString('Reminder 2 of '.PendingConfirmationReminder::MAX_REMINDERS, $body);

    }//end testVersionBumpReminderMentionsTheCheckAndCountsReminders()


    /**
     * Infisical reminder without author or url.
     *
     * @return void
     */
    public function testInfisicalReminderWithoutAuthorOrUrl(): void
    {
        $body = $this->reminder->buildReminder($this->confirmation(PendingConfirmation::KIND_INFISICAL_IGNORE), null, null);

        $this->assertStringStartsWith(PendingConfirmationReminder::MARKER."\nHi, ", $body);
        $this->assertStringContainsString('`.infisicalignore`', $body);
        $this->assertStringContainsString('an earlier comment', $body);
        $this->assertStringNotContainsString('GStraccini Checks', $body);

    }//end testInfisicalReminderWithoutAuthorOrUrl()


}//end class
