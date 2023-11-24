<?php

ini_set("default_charset", "UTF-8");
if (!isset($_SERVER['REQUEST_METHOD'])) {
    ini_set("date.timezone", "America/Sao_Paulo");
}
mb_internal_encoding("UTF-8");

define("USER_AGENT", "gstraccini-bot (+https://github.com/apps/gstraccini-bot/)");

$mySqlSecretsFile = "secrets/mySql.secrets.php";
if (file_exists($mySqlSecretsFile)) {
    require_once $mySqlSecretsFile;
}

$gitHubAppSecretsFile = "secrets/gitHubApp.secrets.php";
if (file_exists($gitHubAppSecretsFile)) {
    require_once $gitHubAppSecretsFile;
}

$healthChecksIoSecretsFile = "secrets/healthChecksIo.secrets.php";
if (file_exists($healthChecksIoSecretsFile)) {
    require_once $healthChecksIoSecretsFile;
}

function loadConfig()
{
    $fileName = "config/config.json";
    if (!file_exists($fileName)) {
        return array();
    }

    $rawConfig = file_get_contents($fileName);
    return json_decode($rawConfig);
}

require_once "lib/functions.php";
require_once "lib/database.php";
require_once "lib/github.php";
