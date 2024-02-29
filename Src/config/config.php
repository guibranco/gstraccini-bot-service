<?php

ini_set("default_charset", "UTF-8");
if (!isset($_SERVER['REQUEST_METHOD'])) {
    ini_set("date.timezone", "America/Sao_Paulo");
}
mb_internal_encoding("UTF-8");

define("USER_AGENT", "gstraccini-bot/1.0 (+https://github.com/apps/gstraccini-bot/)");

$appVeyorSecretsFile = "secrets/appVeyor.secrets.php";
if (file_exists($appVeyorSecretsFile)) {
    require_once $appVeyorSecretsFile;
}

$gitHubAppSecretsFile = "secrets/gitHubApp.secrets.php";
if (file_exists($gitHubAppSecretsFile)) {
    require_once $gitHubAppSecretsFile;
}

$healthChecksIoSecretsFile = "secrets/healthChecksIo.secrets.php";
if (file_exists($healthChecksIoSecretsFile)) {
    require_once $healthChecksIoSecretsFile;
}

$mySqlSecretsFile = "secrets/mySql.secrets.php";
if (file_exists($mySqlSecretsFile)) {
    require_once $mySqlSecretsFile;
}

$rabbitMqSecretsFile = "secrets/rabbitMq.secrets.php";
if (file_exists($rabbitMqSecretsFile)) {
    require_once $rabbitMqSecretsFile;
}

function loadConfig()
{
    $fileNameConfig = "config/config.json";
    $fileNameCommands = "config/commands.json";
    $config = new \stdClass();

    if(file_exists($fileNameConfig)) {
        $rawConfig = file_get_contents($fileNameConfig);
        $config = json_decode($rawConfig);
    }

    $config->commands = array();
    if(file_exists($fileNameCommands)) {
        $rawCommands = file_get_contents($fileNameCommands);
        $commands = json_decode($rawCommands);
        $config->commands = $commands;
    }

    return $config;
}

require_once "lib/functions.php";
require_once "lib/database.php";
require_once "lib/request.php";
require_once "lib/appveyor.php";
require_once "lib/github.php";
require_once "lib/queue.php";
