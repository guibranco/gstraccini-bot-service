<?php

ini_set("default_charset", "UTF-8");
ini_set("date.timezone", "Europe/Dublin");
mb_internal_encoding("UTF-8");

function checkSystemUpdating(): void
{
    $updatingLockFile = "updating.lock";
    $updatingReleaseFile = "updating.release";

    if (!file_exists($updatingLockFile)) {
        return;
    }

    if (file_exists($updatingReleaseFile)) {
        unlink($updatingLockFile);
        unlink($updatingReleaseFile);
        return;
    }

    $fileTime = filemtime($updatingLockFile);

    if ($fileTime === false) {
        $error = "Error: Unable to read update lock file timestamp";
        error_log($error);
        die($error);
    }

    if ($fileTime < strtotime("-15 minute")) {
        $date = date("H:i;s d/m/Y", $fileTime);
        die("System updating since {$date}");
    }

    unlink($updatingLockFile);
    unlink($updatingReleaseFile);
}

checkSystemUpdating();

$version = "1.0.0";
$versionFile = "version.txt";
if (file_exists($versionFile)) {
    $version = trim(file_get_contents($versionFile));
}

define("USER_AGENT_VENDOR", "gstraccini-bot-service/{$version} (+https://github.com/guibranco/gstraccini-bot-service/)");
define("USER_AGENT", "User-Agent: " . USER_AGENT_VENDOR);

require_once "vendor/autoload.php";

use GuiBranco\Pancake\Logger;

$appVeyorSecretsFile = "secrets/appVeyor.secrets.php";
if (file_exists($appVeyorSecretsFile)) {
    require_once $appVeyorSecretsFile;
}

$codacySecretsFile = "secrets/codacy.secrets.php";
if (file_exists($codacySecretsFile)) {
    require_once $codacySecretsFile;
}

$codecovSecretsFile = "secrets/codecov.secrets.php";
if (file_exists($codecovSecretsFile)) {
    require_once $codecovSecretsFile;
}

$healthChecksIoSecretsFile = "secrets/healthChecksIo.secrets.php";
if (file_exists($healthChecksIoSecretsFile)) {
    require_once $healthChecksIoSecretsFile;
}

$gitHubAppSecretsFile = "secrets/gitHubApp.secrets.php";
if (file_exists($gitHubAppSecretsFile)) {
    require_once $gitHubAppSecretsFile;
}

$loggerSecretsFile = "secrets/logger.secrets.php";
if (file_exists($loggerSecretsFile)) {
    require_once $loggerSecretsFile;
}
$logger = new Logger($loggerUrl, $loggerApiKey, $loggerApiToken, constant("USER_AGENT_VENDOR"));

$mySqlSecretsFile = "secrets/mySql.secrets.php";
if (file_exists($mySqlSecretsFile)) {
    require_once $mySqlSecretsFile;
}

$rabbitMqSecretsFile = "secrets/rabbitMq.secrets.php";
if (file_exists($rabbitMqSecretsFile)) {
    require_once $rabbitMqSecretsFile;
}

/**
 * Loads the configuration settings.
 *
 * @return stdClass An object containing the configuration settings.
 */
function loadConfig(): stdClass
{
    $fileNameConfig = "config/config.json";
    $fileNameCommands = "config/commands.json";
    $config = new \stdClass();

    if (file_exists($fileNameConfig)) {
        $rawConfig = file_get_contents($fileNameConfig);
        $config = json_decode($rawConfig);
    }

    if (isset($config) === false || isset($config->debug) === false) {
        die();
    }

    $config->commands = array();
    if (file_exists($fileNameCommands)) {
        $rawCommands = file_get_contents($fileNameCommands);
        $commands = json_decode($rawCommands);
        $config->commands = $commands;
    }

    return $config;
}

require_once "lib/database.php";
require_once "lib/appveyor.php";
require_once "lib/codacy.php";
require_once "lib/github.php";
require_once "lib/queue.php";
