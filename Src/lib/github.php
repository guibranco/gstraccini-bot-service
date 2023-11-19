<?php

use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

function requestGitHub($gitHubToken, $url, $data = null)
{
    $baseUrl = "https://api.github.com/";

    $headers = array();
    $headers[] = "User-Agent: " . USER_AGENT;
    $headers[] = "Content-type: application/json";
    $headers[] = "Authorization: Bearer " . $gitHubToken;

    $fields = array(
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
    $headers = getHeaders($header);
    $body = substr($response, $headerSize);
    curl_close($curl);

    return array("headers" => $headers, "body" => $body);
}

function generateAppToken()
{
    global $gitHubAppId, $gitHubAppPrivateKey;

    $tokenBuilder = new Builder(new JoseEncoder(), ChainedFormatter::default());
    $algorithm = new Sha256();
    $signingKey = InMemory::plainText($gitHubAppPrivateKey);
    $base = new \DateTimeImmutable();
    $now = $base->setTime(date('H'), date('i'), date('s'));

    $token = $tokenBuilder
        ->issuedBy($gitHubAppId)
        ->issuedAt($now->modify('-1 minute'))
        ->expiresAt($now->modify('+5 minutes'))
        ->getToken($algorithm, $signingKey);

    return $token->toString();
}

function generateInstallationToken($installationId, $repositoryName, $permissions = null)
{
    $gitHubAppToken = generateAppToken();

    $data = new \stdClass();
    $data->repository = $repositoryName;
    if (!is_null($permissions) && !empty($permissions)) {
        $data->permissions = $permissions;
    }
    $response = requestGitHub($gitHubAppToken, "app/installations/" . $installationId . "/access_tokens", $data);

    $json = json_decode($response["body"]);
    return $json->token;
}
