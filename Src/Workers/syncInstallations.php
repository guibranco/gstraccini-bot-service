<?php

chdir(__DIR__ . '/../');
require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\Sync\InstallationsSyncHandler;

/**
 * Manual trigger for the installations sync (Handlers/Sync/InstallationsSyncHandler),
 * which otherwise runs monthly from the installations worker's loop. Run
 * directly from the CLI:
 *
 *   php Src/Workers/syncInstallations.php [--installation=<id>] [--force] [--throttle=<seconds>] [--dry-run]
 *
 * or over HTTP via `POST /v1/jobs/syncInstallations/` (see Src/api/v1/jobs.php),
 * which require_once's this same file after responding 202 — in that case
 * options come from the query string instead of argv (`installation`,
 * `force`, `throttle`, `dryRun`).
 *
 * Options:
 *   --installation=<id>  Only check this installation (nothing is marked uninstalled).
 *   --force              Re-sync every installation's repositories, ignoring the 3-month rule.
 *   --throttle=<seconds> Seconds to sleep between GitHub requests (default 1).
 *   --dry-run            Report what would change without writing anything.
 *
 * A manual run doesn't touch the worker's monthly schedule: the worker
 * still runs its own pass on the 1st.
 *
 * Exits with status 1 (CLI) when the run failed.
 */

const SYNC_INSTALLATIONS_DEFAULT_THROTTLE_SECONDS = 1;

function syncInstallationsOptionsFromCli(): array
{
    $options = getopt("", ["installation:", "force", "throttle:", "dry-run"]);

    return [
        "installationId" => isset($options["installation"]) ? (int) $options["installation"] : null,
        "force" => array_key_exists("force", $options),
        "throttleSeconds" => isset($options["throttle"]) ? (int) $options["throttle"] : SYNC_INSTALLATIONS_DEFAULT_THROTTLE_SECONDS,
        "dryRun" => array_key_exists("dry-run", $options),
    ];
}

function syncInstallationsOptionsFromRequest(): array
{
    $flag = static fn(string $name): bool => isset($_GET[$name]) && $_GET[$name] !== "0" && $_GET[$name] !== "false";

    return [
        "installationId" => isset($_GET["installation"]) ? (int) $_GET["installation"] : null,
        "force" => $flag("force"),
        "throttleSeconds" => isset($_GET["throttle"]) ? (int) $_GET["throttle"] : SYNC_INSTALLATIONS_DEFAULT_THROTTLE_SECONDS,
        "dryRun" => $flag("dryRun"),
    ];
}

$options = php_sapi_name() === "cli" ? syncInstallationsOptionsFromCli() : syncInstallationsOptionsFromRequest();

try {
    $handler = new InstallationsSyncHandler($options["throttleSeconds"], $options["dryRun"], $version);
    $handler->run($options["installationId"], $options["force"]);
} catch (\Throwable $e) {
    echo "Installations sync failed: {$e->getMessage()} ❌\n";
    $logger->log("Installations sync failed: {$e->getMessage()}", ['error' => ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]]);
    $logStream?->error("Installations sync failed: {$e->getMessage()}", ['file' => $e->getFile(), 'line' => $e->getLine()], "sync_installations");

    if (php_sapi_name() === "cli") {
        exit(1);
    }
}
