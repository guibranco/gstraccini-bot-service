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

function sendHealthCheck($type=null)
{
    if (isset($_SERVER['REQUEST_METHOD'])) {
        return;
    }
    global $healthChecksIoUuid;

    $curl = curl_init();
    curl_setopt_array(
        $curl,
        array(
            CURLOPT_URL => "https://hc-ping.com/" . $healthChecksIoUuid . ($type == null ? "" : $type),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => false
        )
    );
    curl_exec($curl);
    curl_close($curl);
}
