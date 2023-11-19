<?php

ini_set("default_charset", "UTF-8");
if (!isset($_SERVER['REQUEST_METHOD'])) {
    ini_set("date.timezone", "America/Sao_Paulo");
}
mb_internal_encoding("UTF-8");

define("USER_AGENT", "gstraccini-bot (+https://github.com/apps/gstraccini-bot/)");

$mySqlSecretsFile = "mySql.secrets.php";
if (file_exists($mySqlSecretsFile)) {
    require_once $mySqlSecretsFile;
}

$gitHubAppSecretsFile = "gitHubApp.secrets.php";
if (file_exists($gitHubAppSecretsFile)) {
    require_once $gitHubAppSecretsFile;
}

$healthChecksIoSecretsFile = "healthChecksIo.secrets.php";
if (file_exists($healthChecksIoSecretsFile)) {
    require_once $healthChecksIoSecretsFile;
}

function loadConfig()
{
    if (!file_exists("config.json")) {
        return array();
    }

    $rawConfig = file_get_contents("config.json");
    return json_decode($rawConfig);
}
