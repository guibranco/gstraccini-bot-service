<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\BranchesHandler;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new BranchesHandler();
$healthCheck = new HealthChecks($healthChecksIoBranches, GUIDv4::random());
$processor = new ProcessingManager("branches", $healthCheck, $logger, $logStream);
$processor->initialize([$handler, "handleItem"], 55);
