<?php

use GuiBranco\Pancake\Request;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

function requestGitHub($gitHubToken, $url, $data = null, $isDeleteRequest = false, $isPutRequest = false, $isPatchRequest = false)
{
    $baseUrl = "https://api.github.com/";
    $url = $baseUrl . $url;

    $request = new Request();

    $headers = array(
        "User-Agent: " . USER_AGENT,
        "Content-type: application/json",
        "Accept: application/json",
        "X-GitHub-Api-Version: 2022-11-28",
        "Authorization: Bearer " . $gitHubToken
    );

    $request = new Request();

    if ($isDeleteRequest && $data == null) {
        $response = $request->delete($url, $headers);
    } elseif ($isDeleteRequest && $data != null) {
        $response = $request->delete($url, $data, $headers);
    } elseif ($isPutRequest) {
        $response = $request->put($url, $data, $headers);
    } elseif ($isPatchRequest) {
        $response = $request->patch($url, $data, $headers);
    } elseif ($data != null) {
        $response = $request->post($url, $data, $headers);
    } else {
        $response = $request->get($url, $headers);
    }

    if ($response->statusCode >= 300) {
        sendQueue("github.error", array("url" => $url, "data" => $data), $response);
    }

    return $response;
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

    $json = json_decode($response->body);
    return $json->token;
}
