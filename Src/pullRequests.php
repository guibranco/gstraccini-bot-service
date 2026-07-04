<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\PullRequestsHandler;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new PullRequestsHandler();
$healthCheck = new HealthChecks($healthChecksIoPullRequests, GUIDv4::random());
$processor = new ProcessingManager("pull_requests", $healthCheck, $logger, $logStream);
$processor->initialize([$handler, "handleItem"], 55);
