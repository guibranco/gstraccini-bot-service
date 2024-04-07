<?php

use GuiBranco\Pancake\Request;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

function doRequestGitHub($token, $url, $data, $method)
{
    $baseUrl = "https://api.github.com/";
    $url = $baseUrl . $url;

    if ($data != null) {
        $data = json_encode($data);
    }

    $headers = array(
        USER_AGENT,
        "Content-type: application/json",
        "Accept: application/json",
        "X-GitHub-Api-Version: 2022-11-28",
        "Authorization: Bearer " . $token
    );

    $request = new Request();
    switch ($method) {
        case "GET":
            $response = $request->get($url, $headers);
            break;
        case "POST":
            $response = $request->post($url, $data, $headers);
            break;
        case "PUT":
            $response = $request->put($url, $data, $headers);
            break;
        case "PATCH":
            $response = $request->patch($url, $data, $headers);
            break;
        case "DELETE":
            if ($data == null) {
                $response = $request->delete($url, $headers);
                break;
            }
            $response = $request->delete($url, $data, $headers);
            break;
        default:
            sendQueue("github.error", array("url" => $url, "method" => $method, "data" => $data), "Invalid method");
            break;
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

    $url = "app/installations/" . $installationId . "/access_tokens";
    $response = doRequestGitHub($gitHubAppToken, $url, $data, "POST");
    $json = json_decode($response->body);
    return $json->token;
}
