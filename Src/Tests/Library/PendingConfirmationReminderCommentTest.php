<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\PendingConfirmation;
use GuiBranco\GStracciniBot\Library\PendingConfirmationReminder;
use PHPUnit\Framework\TestCase;

/**
 * What the reminder comments of PendingConfirmationReminder say.
 */
class PendingConfirmationReminderCommentTest extends TestCase
{


    /**
     * Builds the reminder under test.
     *
     * @return PendingConfirmationReminder
     */
    private function reminder(): PendingConfirmationReminder
    {
        return new PendingConfirmationReminder(new InMemoryPendingConfirmationStore(), static fn(): string => FakeGitHub::TOKEN);

    }//end reminder()


    /**
     * Builds a confirmation.
     *
     * @param string  $kind          The kind.
     * @param integer $reminderCount How many reminders were posted.
     *
     * @return PendingConfirmation
     */
    private function confirmation(string $kind, int $reminderCount=0): PendingConfirmation
    {
        return new PendingConfirmation(5, $kind, 'owner', 'repo', 7, 1, 99, null, $reminderCount);

    }//end confirmation()


    /**
     * Version bump reminder mentions the check and counts reminders.
     *
     * @return void
     */
    public function testVersionBumpReminderMentionsTheCheckAndCountsReminders(): void
    {
        $body = $this->reminder()->buildReminder($this->confirmation(PendingConfirmation::KIND_VERSION_BUMP, 1), 'someone', 'https://x.test');

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
        $body = $this->reminder()->buildReminder($this->confirmation(PendingConfirmation::KIND_INFISICAL_IGNORE), null, null);

        $this->assertStringStartsWith(PendingConfirmationReminder::MARKER."\nHi, ", $body);
        $this->assertStringContainsString('`.infisicalignore`', $body);
        $this->assertStringContainsString('an earlier comment', $body);
        $this->assertStringNotContainsString('GStraccini Checks', $body);

    }//end testInfisicalReminderWithoutAuthorOrUrl()


}//end class
