<?php

chdir(__DIR__ . '/../');
require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\CommentsHandler;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new CommentsHandler();
$healthCheck = new HealthChecks($healthChecksIoComments, GUIDv4::random());
$processor = new ProcessingManager("comments", $healthCheck, $logger, $logStream);
$processor->run([$handler, "handleItem"], 60);
