<?php

use GuiBranco\Pancake\Request;
use GuiBranco\Pancake\Response;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

/**
 * The function `doRequestGitHub` sends HTTP requests to the GitHub API with specified parameters and
 * handles different HTTP methods.
 *
 * @param string token The `token` parameter in the `doRequestGitHub` function is the access token used
 * for authentication when making requests to the GitHub API. This token is required to authenticate
 * and authorize the requests being made on behalf of a user or application. It typically starts with
 * "Bearer" followed by a long string
 * @param string url The `url` parameter in the `doRequestGitHub` function represents the specific
 * endpoint or resource path that you want to interact with on the GitHub API. It should be provided as
 * a string and appended to the base GitHub API URL to form the complete URL for the request.
 * @param mixed data The `` parameter in the `doRequestGitHub` function is used to pass any data
 * that needs to be sent along with the request. This data will be encoded as JSON before sending the
 * request to the GitHub API. It can be an array, object, or any other type that can be
 * @param string method The `method` parameter in the `doRequestGitHub` function specifies the HTTP
 * method to be used for the request. It can be one of the following values:
 *
 * @return stdClass The function `doRequestGitHub` returns an object of type `stdClass` which
 * represents the response of the GitHub API request.
 */
function doRequestGitHub(string $token, string $url, mixed $data, string $method): Response
{
    global $logger;

    $baseUrl = "https://api.github.com/";
    $url = $baseUrl . $url;

    if ($data !== null) {
        $data = json_encode($data);
    }

    $headers = array(
        constant("USER_AGENT"),
        "Content-type: application/json",
        "Accept: application/vnd.github+json",
        "X-GitHub-Api-Version: 2022-11-28",
        "Authorization: Bearer " . $token
    );

    $request = new Request();
    switch ($method) {
        case "GET":
            $response = $request->get($url, $headers);
            break;
        case "POST":
            $response = $request->post($url, $headers, $data);
            break;
        case "PUT":
            $response = $request->put($url, $headers, $data);
            break;
        case "PATCH":
            $response = $request->patch($url, $headers, $data);
            break;
        case "DELETE":
            if ($data === null) {
                $response = $request->delete($url, $headers);
                break;
            }
            $response = $request->delete($url, $headers, $data);
            break;
        default:
            $response = Response::error("Invalid method: {$method}", $url, -2);
            break;
    }

    $statusCode = $response->getStatusCode();
    $info = $response->toJson();
    if ($statusCode <= 0 || ($statusCode >= 300 && $statusCode !== 404)) {
        $logger->log("Invalid GitHub response", $info);
    } elseif (empty($response->getBody()) && $method !== "DELETE" && $statusCode !== 204) {
        $logger->log("Unexpected empty response", $info);
    }

    return $response;
}

function getPullRequestDiff(array $metadata): Response
{
    global $logger;

    $baseUrl = "https://api.github.com/";
    $url = $baseUrl . $metadata["pullRequestUrl"];

    $headers = array(
        constant("USER_AGENT"),
        "Accept: application/vnd.github.v3.diff",
        "X-GitHub-Api-Version: 2022-11-28",
        "Authorization: Bearer " . $metadata["token"]
    );

    $request = new Request();
    $response = $request->get($url, $headers);

    $statusCode = $response->getStatusCode();
    if ($statusCode <= 0 || $statusCode >= 300) {
        $info = $response->toJson();
        $logger->log("Invalid GitHub response", $info);
    }

    return $response;
}

/**
 * The function `generateAppToken` generates a token for a GitHub app using specified parameters and
 * returns it as a string.
 *
 * @return string The function `generateAppToken()` returns a string value, which is the generated
 * token for the GitHub application using the provided GitHub App ID and private key.
 */
function generateAppToken(): string
{
    global $gitHubAppId, $gitHubAppPrivateKey, $logger;

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

/**
 * The function generates an installation token for a GitHub repository with optional permissions.
 *
 * @param string installationId The `installationId` parameter in the `generateInstallationToken`
 * function is the unique identifier for the GitHub App installation. This ID is used to specify which
 * installation of the GitHub App you want to generate an access token for.
 * @param string repositoryName The `repositoryName` parameter in the `generateInstallationToken`
 * function is a string that represents the name of the repository for which you want to generate an
 * installation token. This token can be used to authenticate and authorize access to the specified
 * repository on GitHub.
 * @param array permissions The `permissions` parameter in the `generateInstallationToken` function is
 * an optional array that allows you to specify the permissions you want to grant to the installation
 * token. These permissions determine what actions the token can perform on the repository.
 *
 * @return string The function `generateInstallationToken` returns a string, which is the access token
 * generated for the specified installation ID and repository name with optional permissions.
 */
function generateInstallationToken(string $installationId, string $repositoryName, array $permissions = null): string
{
    global $logger;

    $gitHubAppToken = generateAppToken();

    $data = new \stdClass();
    $data->repository = $repositoryName;
    if (!is_null($permissions) && !empty($permissions)) {
        $data->permissions = $permissions;
    }

    $url = "app/installations/" . $installationId . "/access_tokens";
    $response = doRequestGitHub($gitHubAppToken, $url, $data, "POST");

    if ($response->getStatusCode() >= 300) {
        $info = $response->toJson();
        $logger->log("Invalid GitHub response", $info);
    }

    $json = json_decode($response->getBody());
    return $json->token;
}

/**
 * The function `setCheckRunInProgress` sends a request to GitHub API to create a new check run in
 * progress for a specific commit.
 *
 * @param array metadata The `metadata` parameter in the `setCheckRunInProgress` function is an array
 * that contains information needed to make a request to the GitHub API. It typically includes the
 * GitHub token and the URL for the check run endpoint. This information is used to authenticate the
 * request and specify where the check run should
 * @param string commitId The `commitId` parameter in the `setCheckRunInProgress` function is a string
 * that represents the unique identifier of a commit in a version control system, typically a Git
 * commit hash. This identifier is used to associate the check run with a specific commit in the
 * repository.
 * @param string type The `type` parameter in the `setCheckRunInProgress` function represents the type
 * of checks being run. It is used to generate the name of the check run displayed on GitHub. The
 * function appends the capitalized `type` to "GStraccini Checks: " to create the check run
 *
 * @return int The function `setCheckRunInProgress` is returning an integer value, which is the ID of
 * the check run created on GitHub.
 */
function setCheckRunInProgress(array $metadata, string $commitId, string $type): int
{
    global $logger;

    $checkRunBody = array(
        "name" => "GStraccini Checks: " . ucwords($type),
        "details_url" => $metadata["dashboardUrl"],
        "head_sha" => $commitId,
        "status" => "in_progress",
        "output" => array(
            "title" => "Running checks...",
            "summary" => "GStraccini is checking this " . strtolower($type) . "!",
            "text" => ""
        )
    );

    $response = doRequestGitHub($metadata["token"], $metadata["checkRunUrl"], $checkRunBody, "POST");

    if ($response->getStatusCode() >= 300 || empty($response->getBody()) === true) {
        $info = $response->toJson();
        $logger->log("Invalid GitHub response", $info);
    }

    $result = json_decode($response->getBody());
    return $result->id;
}

/**
 * The function `setCheckRunFailed` updates a GitHub check run to mark it as failed with a specific
 * details.
 *
 * @param array metadata The `metadata` parameter is an array containing information needed for the
 * GitHub check run. It includes the following keys:
 * @param int checkRunId The `checkRunId` parameter is an integer that represents the unique identifier
 * of a specific check run in GitHub. It identifies the check run you want to update with
 * the new status and details provided in the function `setCheckRunFailed`.
 * @param string type The `type` parameter in the `setCheckRunFailed` function represents the type of
 * check that was performed. It is used to generate the name and summary for the check run that is
 * marked as failed.
 * @param string details The `details` parameter in the `setCheckRunFailed` function is a string that
 * contains information about the error or failure that occurred during the check run. This information
 * will be included in the output of the check run on GitHub to provide more context about the failure.
 */
function setCheckRunFailed(array $metadata, int $checkRunId, string $type, string $details): void
{
    global $logger;

    $checkRunBody = array(
        "name" => "GStraccini Checks: " . ucwords($type),
        "details_url" => $metadata["dashboardUrl"],
        "status" => "completed",
        "conclusion" => "failure",
        "output" => array(
            "title" => "Checks failed ❌",
            "summary" => "GStraccini checked this " . strtolower($type) . ". Error found!",
            "text" => $details
        )
    );

    $response = doRequestGitHub($metadata["token"], $metadata["checkRunUrl"] . "/" . $checkRunId, $checkRunBody, "PATCH");

    if ($response->getStatusCode() >= 300) {
        $info = $response->toJson();
        $logger->log("Invalid GitHub response", $info);
    }
}

/**
 * The function `setCheckRunSucceeded` updates a GitHub check run to mark it as completed and
 * successful with specific details.
 *
 * @param array metadata The `metadata` parameter is an array containing the information required for
 * setting a check run as succeeded. It includes the following keys:
 * @param int checkRunId The `checkRunId` parameter in the `setCheckRunSucceeded` function is an
 * integer that represents the unique identifier of the check run on GitHub that you want to update.
 * This identifier is used to specify which check run you are targeting when updating its status and
 * details.
 * @param string type The `` parameter in the `setCheckRunSucceeded` function represents the type
 * of check that was completed. It is used to customize the check run details and message based on the
 * specific type of check being performed.
 */
function setCheckRunSucceeded(array $metadata, int $checkRunId, string $type, string $details = null): void
{
    global $logger;

    $checkRunBody = array(
        "name" => "GStraccini Checks: " . ucwords($type),
        "details_url" => $metadata["dashboardUrl"],
        "status" => "completed",
        "conclusion" => "success",
        "output" => array(
            "title" => "Checks completed ✅",
            "summary" => "GStraccini checked this " . strtolower($type) . " successfully!",
            "text" => $details ?? "No issues found."
        )
    );

    $response = doRequestGitHub($metadata["token"], $metadata["checkRunUrl"] . "/" . $checkRunId, $checkRunBody, "PATCH");

    if ($response->getStatusCode() >= 300) {
        $info = $response->toJson();
        $logger->log("Invalid GitHub response", $info);
    }
}
