<?php

use GuiBranco\Pancake\Request;

function sendError($message, $details)
{
    global $loggerUrl, $loggerApiKey, $loggerApiToken;

    $headers = array(
        USER_AGENT,
        "Content-Type: application/json; charset=UTF-8",
        "X-API-KEY: " . $loggerApiKey,
        "X-API-TOKEN: " . $loggerApiToken
    );

    $trace = debug_backtrace();
    $caller = $trace[1];

    $caller["message"] = $message;
    $caller["details"] = $details;
    $caller["object"] = isset($caller["object"]) ? print_r($caller["object"], true) : "";
    $caller["args"] = isset($caller["args"]) ? print_r($caller["args"], true) : "";

    $body = json_encode($caller);
    $url = $loggerUrl . "log-message";

    $request = new Request();
    $request->post($url, $body, $headers);
}