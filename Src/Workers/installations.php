<?php

chdir(__DIR__ . '/../');
require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\LabelHelper;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;
use GuiBranco\Pancake\LogStream;

/**
 * Handle a single installation item from the ProcessingManager.
 *
 * @param array<string,mixed> $installation
 */
function handleItem(array $installation): void
{
    // Minimal implementation to avoid silently "processing" items with no effect.
    // This can be expanded to perform real processing as needed.
    $installationId = $installation['id'] ?? $installation['installation_id'] ?? 'unknown';

    error_log(sprintf(
        '[installations] Received installation payload (id: %s)'
        , (string) $installationId
    ));
}

$healthCheck = new HealthChecks($healthChecksIoInstallations, GUIDv4::random());
$processor = new ProcessingManager("installations", $healthCheck, $logger, $logStream);
$processor->run("handleItem", 60);
