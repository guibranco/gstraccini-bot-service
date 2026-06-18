<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\LabelHelper;
use GuiBranco\GStracciniBot\Library\LabelService;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\GStracciniBot\Library\RepositoryManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($user){ }

$healthCheck = new HealthChecks($healthChecksIoInstallations, GUIDv4::random());
$processor = new ProcessingManager("installations", $healthCheck, $logger, $logStream);
$processor->initialize("handleItem", 55);
