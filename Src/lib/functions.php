<?php

function extractHeaders($header)
{
    $headers = array();
    foreach (explode("\r\n", $header) as $i => $line) {
        if ($i === 0) {
            $headers['http_code'] = $line;
        } else {
            $explode = explode(": ", $line);
            if (count($explode) == 2) {
                list($key, $value) = $explode;
                $headers[$key] = $value;
            }
        }
    }

    return $headers;
}

function sendHealthCheck($token, $type = null)
{
    if (isset($_SERVER['REQUEST_METHOD'])) {
        return;
    }

    $headers = array();
    $headers[] = "User-Agent: " . USER_AGENT;

    $curl = curl_init();
    curl_setopt_array(
        $curl,
        array(
            CURLOPT_URL => "https://hc-ping.com/" . $token . ($type == null ? "" : $type),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $headers

        )
    );
    curl_exec($curl);
    curl_close($curl);
}

function toCamelCase($inputString)
{
    return preg_replace_callback(
        '/(?:^|_| )(\w)/',
        function ($matches) {
            return strtoupper($matches[1]);
        },
        $inputString
    );
}
