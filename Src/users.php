<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\UsersHandler;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new UsersHandler();
$healthCheck = new HealthChecks($healthChecksIoUsers, GUIDv4::random());
$processor = new ProcessingManager("users", $healthCheck, $logger, $logStream);
$processor->initialize([$handler, "handleItem"], 55);
