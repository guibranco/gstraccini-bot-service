<?php

function getHeaders($header)
{
    $headers = array();
    foreach (explode("\r\n", $header) as $i => $line) {
        if ($i === 0) {
            $headers['http_code'] = $line;
        } else {
            $explode = explode(": ", $line);
            if (count($explode) == 2) {
                list($key, $value) = explode(': ', $line);
                $headers[$key] = $value;
            }
        }
    }

    return $headers;
}
