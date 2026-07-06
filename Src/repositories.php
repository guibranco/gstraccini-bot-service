<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\RepositoriesHandler;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new RepositoriesHandler();
$healthCheck = new HealthChecks($healthChecksIoRepositories, GUIDv4::random());
$processor = new ProcessingManager("repositories", $healthCheck, $logger, $logStream);
$processor->initialize([$handler, "handleItem"], 55);
