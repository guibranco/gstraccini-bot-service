<?php

function requestAppVeyor($url, $data = null)
{
    global $appVeyorKey;

    $baseUrl = "https://ci.appveyor.com/api/";
    $url = $baseUrl . $url;

    return doRequest($url, $appVeyorKey, $data);
}
