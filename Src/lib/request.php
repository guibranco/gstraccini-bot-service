<?php


function doRequest($url, $authorizationBearerToken, $data = null)
{
    $headers = array();
    $headers[] = "User-Agent: " . USER_AGENT;
    $headers[] = "Content-type: application/json";
    $headers[] = "Authorization: Bearer " . $authorizationBearerToken;

    $fields = array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers
    );

    if ($data !== null) {
        $fields[CURLOPT_POST] = true;
        $fields[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    $curl = curl_init();

    curl_setopt_array($curl, $fields);

    $response = curl_exec($curl);

    if ($response === false) {
        echo htmlspecialchars($url);
        echo "\r\n";
        die(curl_error($curl));
    }

    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    $headers = extractHeaders($header);
    $body = substr($response, $headerSize);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return array("status" => $http_code, "headers" => $headers, "body" => $body);
}
