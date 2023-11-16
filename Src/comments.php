<?php

require_once("config.php");
require_once("functions.php");

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

require 'vendor/autoload.php';

function readComments()
{
    global $mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase;

    $mysqli = new mysqli($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);
    if ($mysqli->connect_errno) {
        die("Failed to connect to MySQL: " . $mysqli->connect_error);
    }

    $mysqli->set_charset("utf8mb4");
    $mysqli->query("SELECT * FROM github_comments WHERE Processed = 0");
}

function generateToken()
{
    global $gitHubAppId, $gitHubAppPrivateKey;

    $tokenBuilder = (new Builder(new JoseEncoder(), ChainedFormatter::default()));
    $algorithm = new Sha256();
    $signingKey = InMemory::plainText($gitHubAppPrivateKey);
    $base = new \DateTimeImmutable();
    $now = $base->setTime(date('H'), date('i'), date('s'));

    $token = $tokenBuilder
        ->issuedBy($gitHubAppId)
        ->issuedAt($now->modify('-1 minute'))
        ->expiresAt($now->modify('+10 minutes'))
        ->getToken($algorithm, $signingKey);

    return $token->toString();
}

function requestGitHub($url)
{
    $gitHubToken = generateToken();

    $baseUrl = "https://api.github.com/";

    $headers = array();
    $headers[] = "User-Agent: " . USER_AGENT;
    $headers[] = "Content-type: application/json";
    if (isset($gitHubToken)) {
        $headers[] = "Authorization: Bearer " . $gitHubToken;
    }

    $curl = curl_init();

    curl_setopt_array(
        $curl,
        array(
            CURLOPT_URL => $baseUrl . $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => 1,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTPHEADER => $headers
        )
    );

    $response = curl_exec($curl);

    if ($response === false) {
        echo $url;
        echo "\r\n";
        die(curl_error($curl));
    }

    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    $headers = getHeaders($header);
    $body = substr($response, $headerSize);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return array("headers" => $headers, "body" => $body);
}
