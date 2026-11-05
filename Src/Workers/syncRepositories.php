<?php

chdir(__DIR__ . '/../');
require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\Sync\RepositorySyncHandler;

/**
 * Manual trigger for the repositories sync (Handlers/Sync/RepositorySyncHandler),
 * which otherwise runs hourly, one repository at a time, from the
 * repositories worker's loop. Run directly from the CLI:
 *
 *   php Src/Workers/syncRepositories.php [--repository=<id>] [--limit=<n>] [--throttle=<seconds>] [--dry-run]
 *
 * or over HTTP via `POST /v1/jobs/syncRepositories/` (see Src/api/v1/jobs.php),
 * which require_once's this same file after responding 202 — in that case
 * options come from the query string instead of argv (`repository`,
 * `limit`, `throttle`, `dryRun`).
 *
 * Options:
 *   --repository=<id>     Sync this repository (GitHub id) instead of the queue's next one.
 *   --limit=<n>           Take n repositories from the queue in one run (default 1).
 *   --throttle=<seconds>  Seconds to sleep between GitHub requests (default 1).
 *   --dry-run             Report what would change without writing anything (no claim either).
 *
 * A manual run claims the repositories it syncs (LastSyncDate = now), so
 * the worker's next hourly pass waits an hour from it and moves on to the
 * repositories left in the queue.
 *
 * Exits with status 1 (CLI) when the run failed.
 */

const SYNC_REPOSITORIES_DEFAULT_THROTTLE_SECONDS = 1;
const SYNC_REPOSITORIES_DEFAULT_LIMIT = 1;

function syncRepositoriesOptionsFromCli(): array
{
    $options = getopt("", ["repository:", "limit:", "throttle:", "dry-run"]);

    return [
        "repositoryId" => isset($options["repository"]) ? (int) $options["repository"] : null,
        "limit" => isset($options["limit"]) ? max(1, (int) $options["limit"]) : SYNC_REPOSITORIES_DEFAULT_LIMIT,
        "throttleSeconds" => isset($options["throttle"]) ? (int) $options["throttle"] : SYNC_REPOSITORIES_DEFAULT_THROTTLE_SECONDS,
        "dryRun" => array_key_exists("dry-run", $options),
    ];
}

function syncRepositoriesOptionsFromRequest(): array
{
    $flag = static fn(string $name): bool => isset($_GET[$name]) && $_GET[$name] !== "0" && $_GET[$name] !== "false";

    return [
        "repositoryId" => isset($_GET["repository"]) ? (int) $_GET["repository"] : null,
        "limit" => isset($_GET["limit"]) ? max(1, (int) $_GET["limit"]) : SYNC_REPOSITORIES_DEFAULT_LIMIT,
        "throttleSeconds" => isset($_GET["throttle"]) ? (int) $_GET["throttle"] : SYNC_REPOSITORIES_DEFAULT_THROTTLE_SECONDS,
        "dryRun" => $flag("dryRun"),
    ];
}

$options = php_sapi_name() === "cli" ? syncRepositoriesOptionsFromCli() : syncRepositoriesOptionsFromRequest();

try {
    $handler = new RepositorySyncHandler($options["throttleSeconds"], $options["dryRun"], $version);
    $handler->run($options["repositoryId"], $options["limit"]);
} catch (\Throwable $e) {
    echo "Repositories sync failed: {$e->getMessage()} ❌\n";
    $logger->log("Repositories sync failed: {$e->getMessage()}", ['error' => ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]]);
    $logStream?->error("Repositories sync failed: {$e->getMessage()}", ['file' => $e->getFile(), 'line' => $e->getLine()], "sync_repositories");

    if (php_sapi_name() === "cli") {
        exit(1);
    }
}
