<?php

chdir(__DIR__ . '/../');
require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\SignatureHandler;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new SignatureHandler();
$healthCheck = new HealthChecks($healthChecksIoSignature, GUIDv4::random());
$processor = new ProcessingManager("signature", $healthCheck, $logger, $logStream);
$processor->run([$handler, "handleItem"]);
