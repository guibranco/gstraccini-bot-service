<?php

chdir(__DIR__ . '/../');
require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\InstallationsHandler;
use GuiBranco\GStracciniBot\Handlers\Sync\InstallationsSyncHandler;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new InstallationsHandler();
$healthCheck = new HealthChecks($healthChecksIoInstallations, GUIDv4::random());
$processor = new ProcessingManager("installations", $healthCheck, $logger, $logStream);
// Monthly (1st of the month): registers the app's installations and re-lists their repositories.
$processor->addScheduledJob(InstallationsSyncHandler::scheduledJob($version));
$processor->run([$handler, "handleItem"], 60);
