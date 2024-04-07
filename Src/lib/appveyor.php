<?php

use GuiBranco\Pancake\Request;

function requestAppVeyor($url, $data = null)
{
    global $appVeyorKey;

    $baseUrl = "https://ci.appveyor.com/api/";
    $url = $baseUrl . $url;

    $headers = array(
        "Authorization: Bearer " . $appVeyorKey,
        "Content-Type: application/json",
        USER_AGENT
    );

    $request = new Request();
    return $request->post($url, $data, $headers);
}
