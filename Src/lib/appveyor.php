<?php

use GuiBranco\Pancake\Logger;
use GuiBranco\Pancake\Request;

function requestAppVeyor($url, $data = null, $isPut = false)
{
    global $appVeyorKey, $loggerUrl, $loggerApiKey, $loggerApiToken;

    $baseUrl = "https://ci.appveyor.com/api/";
    $url = $baseUrl . $url;

    $headers = array(
        "Authorization: " . $appVeyorKey,
        "Content-Type: application/json",
        USER_AGENT
    );

    $logger = new Logger($loggerUrl, $loggerApiKey, $loggerApiToken);
    $request = new Request();

    $response = null;

    if ($data != null) {
        $response = $isPut
            ? $request->put($url, jsos_encode($data), $headers)
            : $request->post($url, json_encode($data), $headers);
    } else {
        $response = $request->get($url, $headers);
    }

    if ($response->statusCode >= 300) {
        $info = json_encode(array("url" => $url, "request" => json_encode($data, true), "response" => $response));
        $logger->log("Error on AppVeyor request", $info);
    }

    return $response;
}
