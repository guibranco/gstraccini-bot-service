<?php

namespace GuiBranco\GStracciniBot\Handlers\Sync;

use DateTimeImmutable;
use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;
use GuiBranco\GStracciniBot\Library\Sync\MonthlySchedule;
use GuiBranco\GStracciniBot\Library\Sync\ScheduledJob;
use GuiBranco\GStracciniBot\Library\Sync\SettingsSyncStateStore;
use GuiBranco\GStracciniBot\Library\Sync\SyncRepository;
use GuiBranco\GStracciniBot\Library\Sync\SyncStateStoreInterface;
use Throwable;

/**
 * The monthly installations sync: reconciles `github_installations` with
 * the GitHub App's actual installations (`GET /app/installations`) and,
 * through InstallationRepositoriesSyncHandler, `github_repositories` with
 * what each installation can access.
 *
 * It runs from the installations worker's own loop (`scheduledJob()`,
 * registered by Src/installations.php and Src/Workers/installations.php)
 * on the 1st of every month — or, when the worker wasn't running that day,
 * the first time it checks afterwards; the last run is kept in the
 * database. Src/Workers/syncInstallations.php runs it by hand.
 *
 * For every installation GitHub lists:
 * - not registered yet: its repositories are synced, then the installation
 *   row is added (ProcessingState NEW) with LastSyncDate set when that
 *   sync succeeded;
 * - registered: the account metadata is brought up to date, a `deleted`
 *   mark is reverted (GitHub still lists it), and its repositories are
 *   re-synced when LastSyncDate is NULL or older than
 *   REPOSITORIES_RESYNC_MONTHS — then LastSyncDate moves to now;
 * - suspended (either case): the row is kept/added, but no token can be
 *   minted, so its repositories are left alone and LastSyncDate doesn't
 *   move, which makes it due again next month.
 *
 * Installations registered as active that GitHub no longer lists are marked
 * uninstalled (Status '0', the `deleted` event's write). That decision, like
 * the repository removals, is only taken on a complete listing: the fetcher
 * is strict and aborts the whole run on a failed page.
 *
 * A repositories sync that fails (token refused, listing failed) is logged,
 * counted as failed, and leaves that installation's LastSyncDate untouched;
 * the run carries on with the next installation.
 */
class InstallationsSyncHandler
{
    public const REPOSITORIES_RESYNC_MONTHS = 3;

    /** Day of the month the scheduled run becomes due. */
    public const SCHEDULE_DAY_OF_MONTH = 1;

    /** Key (under SettingsSyncStateStore::KEY_PREFIX) holding the scheduled run's last run date. */
    public const SCHEDULE_STATE_KEY = "sync-installations.last-run";

    /** Seconds between GitHub requests when the sync runs from the worker. */
    public const SCHEDULED_THROTTLE_SECONDS = 1;

    private const LOG_CHANNEL = "sync_installations";

    private SyncRepository $repository;
    private GitHubCollectionFetcher $fetcher;
    private InstallationRepositoriesSyncHandler $repositoriesHandler;
    private bool $dryRun;
    private string $webhooksHandlerVersion;

    /** @var array<string, array<string, int>> */
    private array $report = [];

    public function __construct(
        int $throttleSeconds,
        bool $dryRun,
        string $version,
        ?SyncRepository $repository = null,
        ?GitHubCollectionFetcher $fetcher = null
    ) {
        $this->repository = $repository ?? new SyncRepository();
        $this->fetcher = $fetcher ?? new GitHubCollectionFetcher($throttleSeconds, true);
        $this->dryRun = $dryRun;
        $this->webhooksHandlerVersion = self::LOG_CHANNEL . ":" . $version;
        $this->repositoriesHandler = new InstallationRepositoriesSyncHandler(
            $this->repository,
            $this->fetcher,
            $dryRun,
            $this->webhooksHandlerVersion
        );
    }

    /**
     * The sync as a job for the installations worker's loop
     * (ProcessingManager::addScheduledJob()): a full run, due monthly from
     * SCHEDULE_DAY_OF_MONTH, with the last run kept in the database. A new
     * handler is built for every run so nothing is carried over between
     * months.
     *
     * @param string                       $version The bot version, stamped on the rows the sync writes.
     * @param SyncStateStoreInterface|null $store   Where the last run is kept (the `settings` table by default).
     */
    public static function scheduledJob(string $version, ?SyncStateStoreInterface $store = null): ScheduledJob
    {
        $schedule = new MonthlySchedule($store ?? new SettingsSyncStateStore(), self::SCHEDULE_STATE_KEY, self::SCHEDULE_DAY_OF_MONTH);

        return new ScheduledJob(
            self::LOG_CHANNEL,
            $schedule,
            static function () use ($version): void {
                $handler = new self(self::SCHEDULED_THROTTLE_SECONDS, false, $version);
                $handler->run();
            }
        );
    }

    /**
     * @param int|null $onlyInstallationId If set, only this installation is checked, and nothing is marked uninstalled.
     * @param bool     $force              Re-sync every (non-suspended) installation's repositories regardless of LastSyncDate.
     *
     * @return array<string, array<string, int>> The run's counters, per category.
     */
    public function run(?int $onlyInstallationId = null, bool $force = false): array
    {
        global $logStream;

        $this->report = [
            "installations" => [
                "checked" => 0, "registered" => 0, "updated" => 0, "reactivated" => 0,
                "resynced" => 0, "skipped" => 0, "failed" => 0, "removed" => 0,
            ],
            "repositories" => ["checked" => 0, "added" => 0, "updated" => 0, "renamed" => 0, "removed" => 0],
        ];

        echo "==> Installations sync started" . ($this->dryRun ? " (dry run)" : "") . "\n";
        $logStream?->info(
            "Installations sync started",
            ["dryRun" => $this->dryRun, "installationId" => $onlyInstallationId, "force" => $force],
            self::LOG_CHANNEL
        );

        $appToken = generateAppToken();
        $installations = $this->fetcher->fetchAllPages($appToken, "app/installations");
        $known = $this->repository->getLatestInstallations();
        $now = new DateTimeImmutable();
        $listedIds = [];

        foreach ($installations as $installation) {
            $installationId = (int) $installation->id;
            if ($onlyInstallationId !== null && $installationId !== $onlyInstallationId) {
                continue;
            }

            $listedIds[] = $installationId;
            $this->report["installations"]["checked"]++;

            $login = (string) $installation->account->login;
            $suspended = isset($installation->suspended_at) && $installation->suspended_at !== null;
            echo "Installation: {$login} (#{$installationId})" . ($suspended ? " — suspended ⏸️" : "") . "\n";

            $existing = $known[$installationId] ?? null;
            if ($existing === null) {
                $this->registerInstallation($installation, $suspended);
                continue;
            }

            $this->refreshInstallation($existing, $installation, $suspended, $now, $force);
        }

        if ($onlyInstallationId === null) {
            foreach ($known as $installationId => $row) {
                if (in_array($installationId, $listedIds, true) || $row->Status === "0") {
                    continue;
                }

                echo "Installation: {$row->AccountLogin} (#{$installationId}) — not listed by GitHub any more, marking uninstalled ⚠️\n";
                if (!$this->dryRun) {
                    $this->repository->markInstallationRemoved((int) $row->Sequence);
                }
                $this->report["installations"]["removed"]++;
            }
        }

        echo "==> Installations sync finished\n";
        $this->printSummary();
        $logStream?->info("Installations sync finished", $this->report, self::LOG_CHANNEL);

        return $this->report;
    }

    /**
     * Whether an installation's repositories are due for a re-sync: never
     * synced, or last synced $months or more ago.
     *
     * @param string|null $lastSyncDate The stored LastSyncDate (`Y-m-d H:i:s`), or null when never synced.
     */
    public static function isRepositoriesSyncDue(
        ?string $lastSyncDate,
        DateTimeImmutable $now,
        int $months = self::REPOSITORIES_RESYNC_MONTHS
    ): bool {
        if ($lastSyncDate === null || trim($lastSyncDate) === "") {
            return true;
        }

        $lastSync = new DateTimeImmutable($lastSyncDate, $now->getTimezone());

        return $lastSync <= $now->modify("-{$months} months");
    }

    /**
     * The installation account columns that differ between the stored row
     * and what GitHub reports.
     *
     * @return array<int, string> Changed column names.
     */
    public static function installationChanges(object $existing, object $installation): array
    {
        $current = [
            "AccountLogin" => (string) $existing->AccountLogin,
            "AccountType" => (string) $existing->AccountType,
            "AccountNodeId" => (string) $existing->AccountNodeId,
            "RepositorySelection" => (string) $existing->RepositorySelection,
        ];
        $target = [
            "AccountLogin" => (string) $installation->account->login,
            "AccountType" => (string) $installation->account->type,
            "AccountNodeId" => (string) $installation->account->node_id,
            "RepositorySelection" => (string) $installation->repository_selection,
        ];

        return array_keys(array_diff_assoc($target, $current));
    }

    private function registerInstallation(object $installation, bool $suspended): void
    {
        $installationId = (int) $installation->id;
        $login = (string) $installation->account->login;

        echo "  Not registered — adding ✅\n";
        $this->report["installations"]["registered"]++;

        $synced = false;
        if ($suspended) {
            echo "  Suspended — repositories not synced\n";
            $this->report["installations"]["skipped"]++;
        } else {
            $synced = $this->syncRepositories($installationId, $login);
        }

        if ($this->dryRun) {
            return;
        }

        // Repositories first, installation row last: the installations worker claims a NEW
        // installation within a second and lists its repositories too, so by the time it sees
        // this row every repository already exists and its pass is a no-op instead of a race.
        $sequence = $this->repository->insertInstallation($installation, $this->webhooksHandlerVersion);
        if ($synced) {
            $this->repository->markInstallationSynced($sequence);
        }
    }

    private function refreshInstallation(object $existing, object $installation, bool $suspended, DateTimeImmutable $now, bool $force): void
    {
        $installationId = (int) $installation->id;
        $login = (string) $installation->account->login;
        $sequence = (int) $existing->Sequence;

        $changes = self::installationChanges($existing, $installation);
        if (!empty($changes)) {
            echo "  Updating " . implode(", ", $changes) . " 📝\n";
            if (!$this->dryRun) {
                $this->repository->updateInstallationMetadata($sequence, $installation);
            }
            $this->report["installations"]["updated"]++;
        }

        if ($existing->Status === "0") {
            echo "  Registered as uninstalled but GitHub still lists it — reactivating ♻️\n";
            if (!$this->dryRun) {
                $this->repository->markInstallationActive($sequence);
            }
            $this->report["installations"]["reactivated"]++;
        }

        if ($suspended) {
            echo "  Suspended — repositories not synced\n";
            $this->report["installations"]["skipped"]++;
            return;
        }

        $lastSyncDate = $existing->LastSyncDate;
        if (!$force && !self::isRepositoriesSyncDue($lastSyncDate, $now)) {
            $nextCheck = (new DateTimeImmutable($lastSyncDate, $now->getTimezone()))
                ->modify("+" . self::REPOSITORIES_RESYNC_MONTHS . " months")
                ->format("Y-m-d");
            echo "  Repositories synced on {$lastSyncDate} — due again from {$nextCheck}\n";
            return;
        }

        if ($force) {
            $reason = "forced";
        } elseif ($lastSyncDate === null) {
            $reason = "never synced";
        } else {
            $reason = "last synced on {$lastSyncDate}, over " . self::REPOSITORIES_RESYNC_MONTHS . " months ago";
        }
        echo "  Repositories {$reason} — syncing 🔄\n";

        if ($this->syncRepositories($installationId, $login)) {
            if (!$this->dryRun) {
                $this->repository->markInstallationSynced($sequence);
            }
            $this->report["installations"]["resynced"]++;
        }
    }

    /**
     * @return bool True when the installation's repositories were listed and reconciled.
     */
    private function syncRepositories(int $installationId, string $login): bool
    {
        global $logStream;

        try {
            $result = $this->repositoriesHandler->sync($installationId, $login);
        } catch (Throwable $e) {
            echo "  Repositories sync failed: {$e->getMessage()} ❌\n";
            $logStream?->error(
                "Repositories sync failed for installation {$login}: {$e->getMessage()}",
                ["installationId" => $installationId, "file" => $e->getFile(), "line" => $e->getLine()],
                self::LOG_CHANNEL
            );
            $this->report["installations"]["failed"]++;
            return false;
        }

        foreach ($result as $counter => $value) {
            $this->report["repositories"][$counter] += $value;
        }

        echo "  Repositories: {$result['checked']} checked, {$result['added']} added, {$result['updated']} updated, " .
            "{$result['renamed']} renamed, {$result['removed']} removed\n";

        return true;
    }

    private function printSummary(): void
    {
        echo "\n===== Installations sync summary" . ($this->dryRun ? " (dry run — nothing was written)" : "") . " =====\n";
        foreach ($this->report as $category => $counters) {
            $parts = [];
            foreach ($counters as $name => $value) {
                $parts[] = "{$name}: {$value}";
            }
            echo str_pad($category, 16) . implode("  ", $parts) . "\n";
        }
    }
}
