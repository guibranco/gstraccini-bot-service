<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\LabelHelper;
use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\GStracciniBot\Library\RepositoryManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($installation)
{
    echo "https://github.com/{$installation->AccountLogin}:\n\n";

    $token = generateInstallationToken($installation->InstallationId);

    $metadata = array(
        "token" => $token
    );

    $repositories = doPagedRequestGitHub($metadata["token"], "installation/repositories", 100);
}

$healthCheck = new HealthChecks($healthChecksIoInstallations, GUIDv4::random());
$processor = new ProcessingManager("installations", $healthCheck, $logger);
$processor->initialize("handleItem", 55);
