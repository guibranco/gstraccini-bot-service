<?php

use GuiBranco\Pancake\Logger;
use GuiBranco\Pancake\Request;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

function doRequestGitHub($token, $url, $data, $method)
{
    global $loggerUrl, $loggerApiKey, $loggerApiToken;

    $baseUrl = "https://api.github.com/";
    $url = $baseUrl . $url;

    if ($data != null) {
        $data = json_encode($data);
    }

    $headers = array(
        "User-Agent: " . USER_AGENT,
        "Content-type: application/json",
        "Accept: application/json",
        "X-GitHub-Api-Version: 2022-11-28",
        "Authorization: Bearer " . $token
    );

    $logger = new Logger($loggerUrl, $loggerApiKey, $loggerApiToken, USER_AGENT);
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
            $logger->log("Invalid method: " . $method, array("url" => $url, "method" => $method, "data" => $data));
            break;
    }

    if ($response->statusCode >= 300) {
        $logger->log("Error on GitHub request", array("url" => $url, "data" => $data, "response" => $response));
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

function setCheckRunInProgress($metadata, $commitId, $type)
{
    $checkRunBody = array(
        "name" => "GStraccini Checks: " . ucwords($type),
        "head_sha" => $commitId,
        "status" => "in_progress",
        "output" => array(
            "title" => "Running checks...",
            "summary" => "",
            "text" => ""
        )
    );

    $response = doRequestGitHub($metadata["token"], $metadata["checkRunUrl"], $checkRunBody, "POST");
    $result = json_decode($response->body);
    return $result->id;
}

function setCheckRunCompleted($metadata, $checkRunId, $type)
{
    $checkRunBody = array(
        "name" => "GStraccini Checks: " . ucwords($type),
        "details_url" => $metadata["dashboardUrl"],
        "status" => "completed",
        "conclusion" => "success",
        "output" => array(
            "title" => "Checks completed ✅",
            "summary" => "GStraccini checked this " . strtolower($type) . " successfully!",
            "text" => "No issues found."
        )
    );

    doRequestGitHub($metadata["token"], $metadata["checkRunUrl"] . "/" . $checkRunId, $checkRunBody, "PATCH");
}
