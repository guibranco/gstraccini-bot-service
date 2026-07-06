<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\InstallationsHandler;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new InstallationsHandler();
$healthCheck = new HealthChecks($healthChecksIoInstallations, GUIDv4::random());
$processor = new ProcessingManager("installations", $healthCheck, $logger, $logStream);
$processor->initialize([$handler, "handleItem"], 55);
