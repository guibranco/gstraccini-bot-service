<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Handlers\CommentsHandler;
use GuiBranco\GStracciniBot\Library\PendingConfirmationReminder;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

$handler = new CommentsHandler();
$healthCheck = new HealthChecks($healthChecksIoComments, GUIDv4::random());
$processor = new ProcessingManager("comments", $healthCheck, $logger, $logStream);
// Hourly: reminds PR authors of the bot comments still waiting for their confirmation (version bump, .infisicalignore).
$processor->addScheduledJob(PendingConfirmationReminder::scheduledJob());
$processor->initialize([$handler, "handleItem"], 55);
