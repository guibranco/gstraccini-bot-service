<?php

chdir(__DIR__ . '/../');
require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\LabelHelper;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;
use GuiBranco\Pancake\LogStream;

function handleItem($user) {}

$healthCheck = new HealthChecks($healthChecksIoUsers, GUIDv4::random());
$processor = new ProcessingManager("users", $healthCheck, $logger, $logStream);
$processor->run("handleItem", 60);
