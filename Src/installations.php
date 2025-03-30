<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\GStracciniBot\Repositories\RepositoryRepository;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($installation)
{
    echo "https://github.com/{$installation->AccountLogin}:\n\n";

    $token = generateInstallationToken($installation->InstallationId);

    $repositories = loadAllPages($token, "installation/repositories");

    $repository = new RepositoryRepository();

    foreach ($repositories as $repository) {
        $repository->upsert($repository);
    }
}

$healthCheck = new HealthChecks($healthChecksIoInstallations, GUIDv4::random());
$processor = new ProcessingManager("installations", $healthCheck, $logger);
$processor->initialize("handleItem", 55);
