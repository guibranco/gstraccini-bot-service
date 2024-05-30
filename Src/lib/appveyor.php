<?php

use GuiBranco\Pancake\Logger;
use GuiBranco\Pancake\Request;

function requestAppVeyor($url, $data = null, $isPut = false)
{
    global $appVeyorKey;
    global $loggerUrl, $loggerApiKey, $loggerApiToken;

    $baseUrl = "https://ci.appveyor.com/api/";
    $url = $baseUrl . $url;

    $headers = array(
        "Authorization: Bearer " . $appVeyorKey,
        "Content-Type: application/json",
        USER_AGENT
    );

    $logger = new Logger($loggerUrl, $loggerApiKey, $loggerApiToken);
    $request = new Request();

    $response = null;



    if ($data != null) {
        $response = $isPut ? $request->put($url, $data, $headers) : $request->post($url, $data, $headers);
    } else {
        $response = $request->get($url, $headers);
    }

    if($response->statusCode >= 300) {
        $logger->log("Error on AppVeyor request", array("url" => $url, "data" => $data, "response" => $response));
    }

    return $response;
}
