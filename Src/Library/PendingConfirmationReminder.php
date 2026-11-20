<?php

namespace GuiBranco\GStracciniBot\Library;

use Closure;
use GuiBranco\GStracciniBot\Library\Sync\IntervalSchedule;
use GuiBranco\GStracciniBot\Library\Sync\ScheduledJob;
use GuiBranco\GStracciniBot\Library\Sync\SettingsSyncStateStore;
use GuiBranco\GStracciniBot\Library\Sync\SyncStateStoreInterface;
use Throwable;

/**
 * Reminds pull request authors of the bot comments still waiting for their
 * confirmation (version bump decision, `.infisicalignore` approval), and closes
 * out the tracked ones that were settled while the bot wasn't looking: the pull
 * request was merged or closed, the comment deleted, or the choice applied.
 *
 * Runs as a scheduled job of the comments worker (see scheduledJob()).
 */
class PendingConfirmationReminder
{
    use LogsToStream;

    private const LOG_CHANNEL = 'pending_confirmations';

    /**
     * How long a confirmation waits before a reminder, and between reminders: 3 days.
     */
    public const REMINDER_INTERVAL_SECONDS = 259200;

    /**
     * How many reminders at most per confirmation.
     */
    public const MAX_REMINDERS = 3;

    /**
     * How many confirmations one run looks at, at most.
     */
    public const BATCH_SIZE = 20;

    /**
     * How often the job runs: hourly.
     */
    public const SCHEDULE_INTERVAL_SECONDS = 3600;

    /**
     * Key (under SettingsSyncStateStore::KEY_PREFIX) holding the Unix time of the last run.
     */
    public const SCHEDULE_STATE_KEY = 'pending-confirmations.reminders.last-run';

    /**
     * Marks the bot's reminder comments.
     */
    public const MARKER = '<!-- gstraccini-bot:pending-confirmation-reminder -->';

    /**
     * The tracked confirmations.
     *
     * @var PendingConfirmationStore
     */
    private PendingConfirmationStore $store;

    /**
     * Gives the installation access token of an installation id.
     *
     * @var Closure
     */
    private Closure $tokenProvider;


    /**
     * Sets up the reminder.
     *
     * @param PendingConfirmationStore $store         The tracked confirmations.
     * @param callable                 $tokenProvider Gives the installation access token of an installation id.
     *
     * @return void
     */
    public function __construct(PendingConfirmationStore $store, callable $tokenProvider)
    {
        $this->store         = $store;
        $this->tokenProvider = $tokenProvider(...);

    }//end __construct()


    /**
     * The reminders as a job for the comments worker's loop (ProcessingManager::addScheduledJob()):
     * hourly, counted from the last run recorded in the settings table, so the processes on
     * different hosts don't each run it.
     *
     * @param SyncStateStoreInterface|null $state Where the last run is recorded (default: the settings table).
     *
     * @return ScheduledJob
     */
    public static function scheduledJob(?SyncStateStoreInterface $state=null): ScheduledJob
    {
        $state    = ($state ?? new SettingsSyncStateStore());
        $schedule = new IntervalSchedule(
            self::SCHEDULE_INTERVAL_SECONDS,
            static function () use ($state): ?int {
                $lastRun = $state->get(self::SCHEDULE_STATE_KEY);
                if ($lastRun === null) {
                    return null;
                }

                return (time() - (int) $lastRun);
            }
        );

        return new ScheduledJob(
            self::LOG_CHANNEL,
            $schedule,
            static function () use ($state): void {
                $state->set(self::SCHEDULE_STATE_KEY, (string) time());
                $reminder = new self(
                    new PendingConfirmationStore(),
                    static fn(int $installationId): string => generateInstallationAccessToken((string) $installationId)
                );
                $reminder->run();
            }
        );

    }//end scheduledJob()


    /**
     * Goes through the confirmations due for a reminder.
     *
     * @return array<string, int> How many were reminded, resolved, cancelled, skipped.
     */
    public function run(): array
    {
        $report              = [];
        $report['reminded']  = 0;
        $report['resolved']  = 0;
        $report['cancelled'] = 0;
        $report['skipped']   = 0;

        $due = $this->store->findDueForReminder(self::REMINDER_INTERVAL_SECONDS, self::MAX_REMINDERS, self::BATCH_SIZE);
        foreach ($due as $confirmation) {
            try {
                $outcome = $this->handle($confirmation);
            } catch (Throwable $e) {
                $outcome = 'skipped';
                $this->logToStream(
                    'warning',
                    'Pending confirmation {sequence} of {repo}#{pr} failed: {error}',
                    $this->context($confirmation, ['error' => $e->getMessage()])
                );
            }

            $report[$outcome]++;
        }

        $this->logToStream('info', 'Pending confirmation reminders: {reminded} reminded, {resolved} resolved, {cancelled} cancelled, {skipped} skipped', $report);

        return $report;

    }//end run()


    /**
     * Reminds of one confirmation, or closes it out when it was settled.
     *
     * @param PendingConfirmation $confirmation The confirmation.
     *
     * @return string What happened: reminded, resolved, cancelled or skipped.
     */
    private function handle(PendingConfirmation $confirmation): string
    {
        $token      = ($this->tokenProvider)($confirmation->installationId);
        $repoPrefix = "repos/{$confirmation->owner}/{$confirmation->repo}";

        $pullRequestResponse = doRequestGitHub($token, "{$repoPrefix}/pulls/{$confirmation->pullRequestNumber}", null, 'GET');
        if ($pullRequestResponse->getStatusCode() !== 200) {
            $this->logToStream('warning', 'Could not load {repo}#{pr} to remind of pending confirmation {sequence} (HTTP {status})', $this->context($confirmation, ['status' => $pullRequestResponse->getStatusCode()]));
            return 'skipped';
        }

        $pullRequest = json_decode((string) $pullRequestResponse->getBody());

        if (($pullRequest->state ?? 'open') !== 'open') {
            $resolution = PendingConfirmation::RESOLUTION_CLOSED;
            if (($pullRequest->merged ?? false) === true) {
                $resolution = PendingConfirmation::RESOLUTION_MERGED;
            }

            $this->store->cancelByComment($confirmation->commentId, $resolution);
            return 'cancelled';
        }

        $commentResponse = doRequestGitHub($token, "{$repoPrefix}/issues/comments/{$confirmation->commentId}", null, 'GET');
        if ($commentResponse->getStatusCode() === 404) {
            $this->store->cancelByComment($confirmation->commentId, PendingConfirmation::RESOLUTION_COMMENT_DELETED);
            return 'cancelled';
        }

        if ($commentResponse->getStatusCode() !== 200) {
            return 'skipped';
        }

        $comment = json_decode((string) $commentResponse->getBody());
        if ($this->isApplied((string) ($comment->body ?? '')) === true) {
            $this->store->resolveByComment($confirmation->commentId, PendingConfirmation::RESOLUTION_APPLIED);
            return 'resolved';
        }

        $author       = ($pullRequest->user->login ?? $confirmation->author);
        $url          = ($comment->html_url ?? $confirmation->url);
        $body         = $this->buildReminder($confirmation, $author, $url);
        $postResponse = doRequestGitHub($token, "{$repoPrefix}/issues/{$confirmation->pullRequestNumber}/comments", ['body' => $body], 'POST');
        if ($postResponse->getStatusCode() >= 300) {
            $this->logToStream('warning', 'Could not post the reminder of pending confirmation {sequence} on {repo}#{pr} (HTTP {status})', $this->context($confirmation, ['status' => $postResponse->getStatusCode()]));
            return 'skipped';
        }

        $this->store->markReminded($confirmation->sequence);
        $this->logToStream('info', 'Reminded {author} of pending confirmation {sequence} on {repo}#{pr}', $this->context($confirmation, ['author' => $author]));

        return 'reminded';

    }//end handle()


    /**
     * Whether the comment says its choice was already applied.
     *
     * @param string $body The comment body.
     *
     * @return boolean
     */
    private function isApplied(string $body): bool
    {
        if (str_contains($body, VersionBumpCommentBuilder::COMPLETION_MARKER) === true) {
            return true;
        }

        return str_contains($body, InfisicalIgnoreApprovalCommentBuilder::COMPLETION_MARKER);

    }//end isApplied()


    /**
     * Builds the reminder comment.
     *
     * @param PendingConfirmation $confirmation The confirmation.
     * @param string|null         $author       Who to mention.
     * @param string|null         $url          The comment waiting for the choice.
     *
     * @return string
     */
    public function buildReminder(PendingConfirmation $confirmation, ?string $author, ?string $url): string
    {
        $mention = 'Hi';
        if ($author !== null && $author !== '') {
            $mention = "Hi @{$author}";
        }

        $where = 'an earlier comment';
        if ($url !== null) {
            $where = "[this comment]({$url})";
        }

        $number = ($confirmation->reminderCount + 1);

        $body  = self::MARKER."\n";
        $body .= "{$mention}, this pull request is still waiting for {$confirmation->describe()} in {$where}.\n\n";
        $body .= "Tick one of its boxes and I'll take it from there.";
        if ($confirmation->kind === PendingConfirmation::KIND_VERSION_BUMP) {
            $body .= ' Until then, the **GStraccini Checks: Pull Request** check stays in progress.';
        }

        $body .= "\n\n<sub>Reminder {$number} of ".self::MAX_REMINDERS.".</sub>\n";

        return $body;

    }//end buildReminder()


    /**
     * The log context of a confirmation.
     *
     * @param PendingConfirmation $confirmation The confirmation.
     * @param array               $extra        More context.
     *
     * @return array
     */
    private function context(PendingConfirmation $confirmation, array $extra=[]): array
    {
        $context             = [];
        $context['sequence'] = $confirmation->sequence;
        $context['repo']     = "{$confirmation->owner}/{$confirmation->repo}";
        $context['pr']       = $confirmation->pullRequestNumber;
        $context['kind']     = $confirmation->kind;

        return array_merge($context, $extra);

    }//end context()


}//end class
