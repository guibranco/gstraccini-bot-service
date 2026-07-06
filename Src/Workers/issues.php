<?php

chdir(__DIR__ . '/../');
require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\IssuesHandler;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new IssuesHandler();
$healthCheck = new HealthChecks($healthChecksIoIssues, GUIDv4::random());
$processor = new ProcessingManager("issues", $healthCheck, $logger, $logStream);
$processor->run([$handler, "handleItem"], 60);
