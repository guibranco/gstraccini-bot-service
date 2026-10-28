# Scheduled GitHub sync from the existing installations and repositories workers

`github_installations` and `github_repositories` (shared tables owned by the `webhooks` project) are fed by
webhook events only — `installation` `created` and `repository` `created`. Everything else that changes
what the GitHub App can see is not delivered to the database (`installation_repositories`,
`privatized`/`publicized`), is only partially applied (`renamed` rewrites `Name` but not `FullName`), or is
lost for good when a delivery is missed. The manual checkup job (`Src/Workers/checkup.php`) backfills
missing rows, but only when someone runs it.

The bot now reconciles both tables, and each repository's pull requests, issues and branches, on a
schedule:

- **Installations sync** (`Handlers/Sync/InstallationsSyncHandler`) — monthly, from the 1st: lists the
  app's installations (`GET /app/installations`), registers the missing ones with their repositories,
  updates the account metadata of the known ones, marks the ones GitHub no longer lists as uninstalled
  (`Status = '0'`, the `deleted` event's write), and re-lists the repositories of every installation whose
  `LastSyncDate` is `NULL` or older than 3 months (then moves that date). A repository listing adds
  missing repositories, updates the metadata of known ones (following a rename/transfer into the other
  `github_*` tables keyed by owner/name, as the receiver does for a `renamed` event), and appends the
  `deleted`/`removed` row for repositories the installation can no longer access.
- **Repositories sync** (`Handlers/Sync/RepositorySyncHandler`) — hourly, one repository: takes the active
  repository with the oldest `LastSyncDate` (`NULL` first), stamps the date, refreshes its metadata
  (`GET /repositories/{id}`, by id so renames and transfers are still found) and reconciles its open pull
  requests, open issues and branches against GitHub.

## Decisions

### They run inside the existing workers, not as new ones

Both syncs are registered as `ScheduledJob`s on the existing workers (`ProcessingManager::addScheduledJob()`
in `Src/installations.php`, `Src/Workers/installations.php`, `Src/repositories.php` and
`Src/Workers/repositories.php`). After each batch of queued items — including a batch whose item failed —
the worker asks every registered job to run if its schedule says so, in all three run modes — the
55-second cron/HTTP loop, the single HTTP batch behind `POST /v1/jobs/{worker}/`, and the daemon. The
schedule is consulted at most once a minute, so the daemon's 100 ms loop doesn't hit the database for it.
A failing sync is logged like a failing batch and never stops the worker. No new systemd units, cron entries or HealthChecks.io checks are needed; the
manual entry points (`Src/Workers/syncInstallations.php`, `Src/Workers/syncRepositories.php`, also behind
the jobs API) exist for one-off, targeted or dry runs, like `checkup.php`.

### The run markers live in the database, not in memory

The workers start a fresh process every minute in cron/HTTP mode and restart on every deploy in daemon
mode, so an in-memory "last run" would re-run a monthly job every minute. Each schedule reads its marker
from the database:

- the repositories sync is paced by the newest `github_repositories.LastSyncDate`, i.e. its own last
  claim, with the elapsed seconds computed by the database (`TIMESTAMPDIFF`) so the PHP and database
  clocks' time zones never enter the comparison;
- the installations sync keeps its last run in the shared `settings` table (key
  `gstraccini-bot.sync-installations.last-run`, ISO 8601), and is due once per calendar month from the 1st
  on — "from", so a worker that wasn't running on the day catches up as soon as it is back.

A schedule hands out a run with a **claim** taken *before* the job runs: a job that keeps failing is
retried at its own cadence, not on every check, and two workers checking at the same moment (the
cron-style script next to the daemon, a jobs-API run) can't both take it — the monthly claim is a
compare-and-set on the `settings` row (`INSERT IGNORE` for a first run, a conditional `UPDATE` after),
the hourly one is the repository row's own conditional `UPDATE`. A scheduled run blocks its worker's
loop while it runs, so the daemon pings HealthChecks.io right after one, and the strict fetcher the
syncs use aborts the pass instead of sleeping out a low GitHub rate limit inside the worker.

### `LastSyncDate` is claimed before the sync, and the most recent row per id is "the" row

`github_repositories.LastSyncDate` is set when the hourly sync picks the repository, before any GitHub
call (a conditional `UPDATE`, so two overlapping runs can't take the same repository). A repository whose
sync fails therefore moves to the back of the queue instead of blocking the worker every hour; with N
active repositories each one gets a turn roughly every N hours. `github_installations.LastSyncDate` only
moves after the installation's repositories were listed and reconciled, so a failed listing (suspended
installation, token error) leaves it due next month.

Neither table has a unique key on its GitHub id (one row per event that announced the entity, plus the
`deleted`/`removed` rows the installations handler appends), so every read takes the most recent row
(highest `Sequence`) per `InstallationId` / per `Id` and every write targets that row. The webhooks
migration `Sql/0041` adds the column and the `(id, Sequence)` indexes this needs; see its ADR-0011.
`CheckupRepository::existsRepository()` was aligned to the same rule — it used to count an older
`created` row even after a later row had marked the repository removed, so a re-added repository could
never be registered again. `CheckupRepository::markRepositoryRemoved()`, which the syncs reuse for every
removal, listed 17 columns for 21 values and had never been able to insert its row (the installations
handler's stale-repository path threw on it); its column list now matches.

### The sync never closes a pull request or issue itself

When GitHub no longer lists a stored-as-open pull request or issue as open, the sync sets the row back to
`RE_REQUESTED` (only when the bot had finished with it, so an in-flight processing isn't disturbed) and
the bot's own pull requests / issues handler fetches it and runs the normal closing flow — labels,
notifications, next pull request. Branches GitHub no longer has get the `delete` event's write
(`Event = 'delete'`, `ProcessingState = 'UPDATED'`), which queues them for the branches handler. One
closing code path, the one the webhooks already drive.

### Removals are only decided on a complete listing

`GitHubCollectionFetcher` gained a strict mode that aborts a listing on any failed page instead of
returning what it had. The syncs use it for every list they take removal decisions from (installations,
an installation's repositories, open pull requests, open issues, branches); the checkup keeps the lenient
default because it only ever inserts.

## Considered options

- **Separate sync workers with their own systemd units / timers.** Rejected: the installations and
  repositories workers already exist in every deployment, and plugging into their loop means both the
  cron-style host and the daemon host get the syncs with no extra units, secrets or checks to operate.
- **Run the checkup periodically as it is.** Rejected: it walks every installation, repository, pull
  request, issue, branch and commit in one deliberately slow pass — fine by hand, far too much hourly.
- **Close pull requests and issues directly in the sync.** Rejected: it would duplicate the handlers'
  closing flow and skip its side effects (label cleanup, notifications, the next-pull-request chain).

## Consequences

- Rollout order: **webhooks first** (`Sql/0041`), then this service. Deployed first, the scheduled jobs
  fail every check on the unknown column — logged, and nothing else in the worker is affected — until the
  migration lands.
- On the first monthly run after the migration every installation is "never synced", so every
  installation's repositories are re-listed once; after that only the ones older than 3 months are.
- The hourly sync re-requests processing for pull requests and issues that were closed on GitHub while
  their `closed` event was missed, so the bot's pull requests and issues workers get a little extra
  work right after a sync. Rows re-added by the sync (repositories, pull requests, issues, branches) are
  inserted `NEW` and onboarded by the regular workers, exactly like webhook-created rows.
- The `deleted`/`removed` rows the installations handler and the syncs append are inserted `NEW` too
  (the table has no other default), so they reach the repositories handler like any event. The handler
  finalizes them without a GitHub call — there is nothing to set up for a repository that is gone — and,
  when a token request for a row that still looks active answers 404 (installation gone) or 422
  (repository not accessible), records the removal itself, the way the syncs do, instead of failing and
  being retried every hour.
- `settings` (a webhooks table) now holds one row written by this service; its name is prefixed with
  `gstraccini-bot.` so it can't collide with that project's settings.
