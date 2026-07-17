<?php

chdir(__DIR__ . '/../');
require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\CheckupHandler;

/**
 * Manual reconciliation entry point. Run directly from the CLI:
 *
 *   php Src/Workers/checkup.php [--installation=<id>] [--throttle=<seconds>] [--dry-run]
 *
 * or triggered over HTTP via `POST /v1/jobs/checkup/` (see Src/api/v1/jobs.php),
 * which require_once's this same file after responding 202 — in that case
 * options come from the query string instead of argv.
 */

const DEFAULT_THROTTLE_SECONDS = 2;

function checkupOptionsFromCli(): array
{
    $options = getopt("", ["installation:", "throttle:", "dry-run"]);

    return [
        "installationId" => isset($options["installation"]) ? (int) $options["installation"] : null,
        "throttleSeconds" => isset($options["throttle"]) ? (int) $options["throttle"] : DEFAULT_THROTTLE_SECONDS,
        "dryRun" => array_key_exists("dry-run", $options),
    ];
}

function checkupOptionsFromRequest(): array
{
    return [
        "installationId" => isset($_GET["installation"]) ? (int) $_GET["installation"] : null,
        "throttleSeconds" => isset($_GET["throttle"]) ? (int) $_GET["throttle"] : DEFAULT_THROTTLE_SECONDS,
        "dryRun" => isset($_GET["dryRun"]) && $_GET["dryRun"] !== "0" && $_GET["dryRun"] !== "false",
    ];
}

$options = php_sapi_name() === "cli" ? checkupOptionsFromCli() : checkupOptionsFromRequest();

$handler = new CheckupHandler($options["throttleSeconds"], $options["dryRun"], $version);
$handler->run($options["installationId"]);
