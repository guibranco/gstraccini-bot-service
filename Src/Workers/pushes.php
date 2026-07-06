<?php

chdir(__DIR__ . '/../');
require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\PushesHandler;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new PushesHandler();
$healthCheck = new HealthChecks($healthChecksIoPushes, GUIDv4::random());
$processor = new ProcessingManager("pushes", $healthCheck, $logger, $logStream);
$processor->run([$handler, "handleItem"], 60);
