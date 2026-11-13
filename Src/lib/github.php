<?php

use GuiBranco\Pancake\Request;
use GuiBranco\Pancake\Response;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

define("BOT_CHECK_MESSAGE_PREFIX", "GStraccini Checks: ");


/**
 * Decodes a GitHub API JSON response body and returns the requested property.
 *
 * @param Response $response The response returned by `doRequestGitHub`.
 * @param string   $property The property to read from the decoded response body.
 *
 * @return mixed The value of the requested property.
 *
 * @throws RuntimeException When the response body is not a JSON object or does not contain the
 * requested property (e.g. GitHub returned an error payload instead of the expected resource).
 */
function decodeGitHubResponse(Response $response, string $property): mixed
{
    $decoded = json_decode($response->getBody());

    if (!is_object($decoded) || !property_exists($decoded, $property)) {
        throw new RuntimeException(
            "Unexpected GitHub API response: missing '{$property}' property. " .
            "HTTP status: {$response->getStatusCode()}. Body: {$response->getBody()}"
        );
    }

    return $decoded->$property;
}

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
 * @param string accept The `accept` parameter in the `doRequestGitHub` function specifies the HTTP
 * Accept header value to be used for the request. It can be used to specify the media type of the
 * response expected from the GitHub API. The default value is "application/vnd.github+json".
 *
 * @return Response The function `doRequestGitHub` returns an object of type `Response` which
 * represents the response of the GitHub API request.
 */
function doRequestGitHub(string $token, string $url, mixed $data, string $method, string $accept = "application/vnd.github+json"): Response
{
    return requestGitHub($token, $url, $data, $method, $accept, []);
}

/**
 * Sends a request to the GitHub API, for a caller that handles some error
 * answers itself (e.g. a feature GitHub doesn't offer on the repository's plan): those are
 * logged at debug level instead of as errors.
 *
 * @param string $token               The installation token.
 * @param string $url                 The endpoint, relative to the API root.
 * @param string $method              The HTTP method.
 * @param array  $expectedStatusCodes The error status codes the caller handles itself.
 * @param mixed  $data                The payload, encoded as JSON; null for none.
 *
 * @return Response The response of the GitHub API request.
 */
function doRequestGitHubExpecting(
    string $token,
    string $url,
    string $method,
    array $expectedStatusCodes,
    mixed $data = null
): Response {
    return requestGitHub($token, $url, $data, $method, "application/vnd.github+json", $expectedStatusCodes);
}

/**
 * Sends a request to the GitHub API and logs its answer.
 *
 * @param string $token               The installation token.
 * @param string $url                 The endpoint, relative to the API root.
 * @param mixed  $data                The payload, encoded as JSON; null for none.
 * @param string $method              The HTTP method.
 * @param string $accept              The Accept header.
 * @param array  $expectedStatusCodes The error status codes the caller handles itself.
 *
 * @return Response The response of the GitHub API request.
 */
function requestGitHub(
    string $token,
    string $url,
    mixed $data,
    string $method,
    string $accept,
    array $expectedStatusCodes
): Response {
    global $logger;

    if ($token === null || $token === "") {
        $logger->log("Invalid GitHub token", null);
        return Response::error("Invalid GitHub token", $url, -1);
    }

    $baseUrl = "https://api.github.com/";
    $url = $baseUrl . $url;

    if ($data !== null) {
        $data = json_encode($data);
    }

    $headers = array(
        constant("USER_AGENT"),
        "Content-type: application/json",
        "Accept: {$accept}",
        "X-GitHub-Api-Version: 2022-11-28",
        "Authorization: Bearer {$token}"
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
            $response = $request->delete($url, $headers, $data);
            break;
        default:
            $response = Response::error("Invalid method: {$method}", $url, -2);
            break;
    }

    return handleGitHubResponse($response, $method, $accept, $expectedStatusCodes);
}

/**
 * Logs a GitHub API answer: at debug level when the caller handles its status itself,
 * otherwise as `handleResponse` does.
 *
 * @param Response $response            The response of the GitHub API request.
 * @param string   $method              The HTTP method.
 * @param string   $accept              The Accept header.
 * @param array    $expectedStatusCodes The error status codes the caller handles itself.
 *
 * @return Response The same response.
 */
function handleGitHubResponse(Response $response, string $method, string $accept, array $expectedStatusCodes): Response
{
    global $logStream;

    $statusCode = $response->getStatusCode();
    if (in_array($statusCode, $expectedStatusCodes, true) === false) {
        return handleResponse($response, $method, $accept);
    }

    $errorMessage = buildGitHubErrorMessage($statusCode, $response->getBody());
    $logStream?->debug(
        'GitHub API {method} request returned an expected HTTP {statusCode}: {errorMessage}',
        compact('method', 'statusCode', 'errorMessage'),
        "github"
    );

    return $response;
}

/**
 * The function `handleResponse` logs and validates the response from the GitHub API request.
 *
 * @param Response response The `response` parameter in the `handleResponse` function is an object of
 * type `Response` that represents the response received from the GitHub API request. It contains
 * information such as the status code, headers, and body of the response.
 * @param string method The `method` parameter in the `handleResponse` function is a string that
 * specifies the HTTP method used in the request. It is used to determine the type of response expected
 * and validate the response accordingly.
 * @param string accept The `accept` parameter in the `doRequestGitHub` function specifies the HTTP
 * Accept header value to be used for the request. It can be used to specify the media type of the
 * response expected from the GitHub API. The default value is "application/vnd.github+json".
 *
 * @return Response The function `handleResponse` returns the same `Response` object that was passed
 * as a parameter after logging and validating the response.
 */
function handleResponse(Response $response, string $method, string $accept): Response
{
    global $logger, $logStream;

    $statusCode = $response->getStatusCode();
    $info = $response->toJson();
    $body = $response->getBody();
    $errorMessage = buildGitHubErrorMessage($statusCode, $body);

    if ($statusCode <= 0 || ($statusCode >= 300 && $statusCode !== 404)) {
        $logger->log($errorMessage, $info);
        $logStream?->error(
            'GitHub API {method} request failed with HTTP {status}: {error}',
            ['method' => $method, 'status' => $statusCode, 'error' => $errorMessage, 'response' => $info],
            "github"
        );
    } elseif (empty($body) && $method !== "DELETE" && $statusCode !== 204 && $accept !== "application/vnd.github.v3.diff") {
        $logger->log("Unexpected empty response", $info);
        $logStream?->warning(
            'GitHub API {method} request returned an unexpected empty response (HTTP {status})',
            ['method' => $method, 'status' => $statusCode, 'response' => $info],
            "github"
        );
    }

    return $response;
}

/**
 * Describes a GitHub API answer for the logs: its status code, plus the message, the
 * validation errors and the documentation link of its body when GitHub sent them.
 *
 * @param int         $statusCode The HTTP status code.
 * @param string|null $body       The response body.
 *
 * @return string The description.
 */
function buildGitHubErrorMessage(int $statusCode, ?string $body): string
{
    $errorMessage = "Invalid GitHub response. HTTP Status Code: {$statusCode}";
    $decoded = (empty($body) === false) ? json_decode($body, true) : null;
    if (is_array($decoded) === false) {
        return $errorMessage;
    }

    if (empty($decoded['message']) === false) {
        $errorMessage .= " - {$decoded['message']}";
    }

    if (empty($decoded['errors']) === false && is_array($decoded['errors']) === true) {
        $errorMessage .= "\nErrors:";
        foreach ($decoded['errors'] as $error) {
            $errorMessage .= "\n- " . describeGitHubError($error);
        }
    }

    if (empty($decoded['documentation_url']) === false) {
        $errorMessage .= "\n📚 See: {$decoded['documentation_url']}";
    }

    return $errorMessage;
}

/**
 * Describes one entry of the `errors` list of a GitHub API answer.
 *
 * @param mixed $error The entry: an object with resource, field and code, or a plain message.
 *
 * @return string The description.
 */
function describeGitHubError(mixed $error): string
{
    if (is_array($error) === false) {
        return (string) $error;
    }

    $resource = $error['resource'] ?? 'Unknown resource';
    $field = $error['field'] ?? 'unknown field';
    $code = $error['code'] ?? 'unknown code';

    return "[{$resource}] {$field}: {$code}";
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
    $gitHubAppToken = generateAppToken();

    $data = new \stdClass();
    $data->repository = $repositoryName;
    if (!is_null($permissions) && !empty($permissions)) {
        $data->permissions = $permissions;
    }

    $response = doRequestGitHub($gitHubAppToken, installationAccessTokensUrl($installationId), $data, "POST");
    return decodeGitHubResponse($response, "token");
}

/**
 * Generates an installation access token scoped to every repository the
 * installation can access (no `repository`/`repositories` restriction),
 * unlike `generateInstallationToken()` which scopes the token to a single
 * repository. Used when an operation needs to walk all repositories of an
 * installation (e.g. `GET /installation/repositories`) without minting a
 * new token per repository.
 *
 * @param string $installationId The GitHub App installation ID.
 * @param array|null $permissions Optional permissions to request for the token.
 *
 * @return string The generated installation access token.
 */
function generateInstallationAccessToken(string $installationId, array $permissions = null): string
{
    $gitHubAppToken = generateAppToken();

    $data = null;
    if (!is_null($permissions) && !empty($permissions)) {
        $data = new \stdClass();
        $data->permissions = $permissions;
    }

    $response = doRequestGitHub($gitHubAppToken, installationAccessTokensUrl($installationId), $data, "POST");
    return decodeGitHubResponse($response, "token");
}

/**
 * Requests an installation access token and returns the raw GitHub response
 * instead of the token, so the caller can act on the status code: GitHub
 * answers 404 when the installation no longer exists (the app was
 * uninstalled) and 422 when one of `$repositoryIds` isn't accessible to the
 * installation any more (the repository was removed from it or deleted).
 * The sync workers turn both into "this is gone" instead of an error.
 *
 * Scoping by repository id (not name, as `generateInstallationToken()` does)
 * keeps the request valid after the repository was renamed or transferred.
 *
 * @param string $installationId The GitHub App installation ID.
 * @param array<int>|null $repositoryIds Repository ids to scope the token to; null for every repository of the installation.
 * @param array|null $permissions Optional permissions to request for the token.
 *
 * @return Response The GitHub response; on success its JSON body carries the `token` property.
 */
function requestInstallationAccessToken(string $installationId, ?array $repositoryIds = null, ?array $permissions = null): Response
{
    $gitHubAppToken = generateAppToken();

    $data = null;
    if (!empty($repositoryIds) || !empty($permissions)) {
        $data = new \stdClass();
        if (!empty($repositoryIds)) {
            $data->repository_ids = array_values(array_map('intval', $repositoryIds));
        }
        if (!empty($permissions)) {
            $data->permissions = $permissions;
        }
    }

    return doRequestGitHub($gitHubAppToken, installationAccessTokensUrl($installationId), $data, "POST");
}

/**
 * The GitHub API endpoint that mints access tokens for an installation
 * (`POST /app/installations/{installation_id}/access_tokens`).
 *
 * @param string $installationId The GitHub App installation ID.
 *
 * @return string The endpoint, relative to the GitHub API root.
 */
function installationAccessTokensUrl(string $installationId): string
{
    return "app/installations/" . $installationId . "/access_tokens";
}

/**
 * The function `getPullRequestDiff` retrieves the diff of a pull request from the GitHub API using
 * the provided metadata.
 *
 * @param array metadata The `metadata` parameter in the `getPullRequestDiff` function is an array
 * containing information needed to make a request to the GitHub API. It typically includes the GitHub
 * token and the URL for the pull request endpoint. This information is used to authenticate the request
 * and specify which pull request's diff should be retrieved.
 *
 * @return Response The function `getPullRequestDiff` returns an object of type `Response` which
 * represents the response of the GitHub API request to retrieve the diff of a pull request.
 */
function getPullRequestDiff(array $metadata): Response
{
    return doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"], null, "GET", "application/vnd.github.v3.diff");
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
    $checkRunBody = array(
        "name" => constant("BOT_CHECK_MESSAGE_PREFIX") . ucwords($type),
        "details_url" => $metadata["dashboardUrl"],
        "head_sha" => $commitId,
        "status" => "in_progress",
        "output" => array(
            "title" => "Checks in progress ⏳",
            "summary" => "GStraccini is checking this " . strtolower($type) . "!",
            "text" => ""
        )
    );

    $response = doRequestGitHub($metadata["token"], $metadata["checkRunUrl"], $checkRunBody, "POST");
    return decodeGitHubResponse($response, "id");
}

/**
 * Makes sure GitHub accepted a check run update, so a check run isn't silently left in its
 * previous state (in progress forever, for one) when the PATCH fails.
 *
 * @param Response $response   The response of the PATCH request.
 * @param int      $checkRunId The ID of the check run updated.
 * @param string   $conclusion The conclusion the update set.
 *
 * @return void
 *
 * @throws RuntimeException When GitHub didn't accept the update.
 */
function ensureCheckRunUpdated(Response $response, int $checkRunId, string $conclusion): void
{
    $statusCode = $response->getStatusCode();
    if ($statusCode >= 200 && $statusCode < 300) {
        return;
    }

    $message = sprintf(
        "Failed to set check run #%d to %s (HTTP %d): %s",
        $checkRunId,
        $conclusion,
        $statusCode,
        (string) $response->getBody()
    );
    throw new RuntimeException($message);
}

/**
 * Whether a refused check run update is worth sending again: GitHub sometimes answers 404 to a
 * PATCH on a check run created a moment before (its replicas haven't caught up yet), and 5xx or
 * no answer at all (-1) are transient too. Anything else (401, 403, 422) won't change on retry.
 *
 * @param integer $statusCode The status code of the PATCH.
 *
 * @return boolean
 */
function isRetryableCheckRunUpdate(int $statusCode): bool
{
    return $statusCode === 404 || $statusCode === -1 || $statusCode >= 500;

}//end isRetryableCheckRunUpdate()


/**
 * Sends a check run update, sending it again after a pause while GitHub's answer is transient,
 * and throws (through ensureCheckRunUpdated()) when it still isn't accepted after the last try.
 *
 * @param callable $send       Sends the PATCH and returns its Response.
 * @param callable $pause      Waits the given number of seconds between tries.
 * @param integer  $checkRunId The ID of the check run updated.
 * @param string   $conclusion The conclusion the update sets.
 *
 * @return void
 *
 * @throws RuntimeException When GitHub didn't accept the update.
 */
function sendCheckRunUpdate(callable $send, callable $pause, int $checkRunId, string $conclusion): void
{
    $delays = [1, 2, 4];
    $response = $send();
    foreach ($delays as $delay) {
        if (isRetryableCheckRunUpdate($response->getStatusCode()) === false) {
            break;
        }

        $pause($delay);
        $response = $send();
    }

    ensureCheckRunUpdated($response, $checkRunId, $conclusion);

}//end sendCheckRunUpdate()


/**
 * PATCHes a check run with the given body, retrying transient refusals.
 *
 * @param array   $metadata     Metadata for the GitHub API request (token, checkRunUrl).
 * @param integer $checkRunId   The ID of the check run to update.
 * @param array   $checkRunBody The update.
 * @param string  $conclusion   The conclusion the update sets.
 *
 * @return void
 *
 * @throws RuntimeException When GitHub didn't accept the update.
 */
function patchCheckRun(array $metadata, int $checkRunId, array $checkRunBody, string $conclusion): void
{
    $url = sprintf("%s/%d", $metadata["checkRunUrl"], $checkRunId);
    $send = fn () => doRequestGitHub($metadata["token"], $url, $checkRunBody, "PATCH");
    sendCheckRunUpdate($send, sleep(...), $checkRunId, $conclusion);

}//end patchCheckRun()


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
    global $logStream;

    $logStream?->error(
        'Check run {checkRun} #{checkRunId} failed: {details}',
        ['checkRun' => constant("BOT_CHECK_MESSAGE_PREFIX") . ucwords($type), 'checkRunId' => $checkRunId, 'checkRunUrl' => $metadata["checkRunUrl"], 'details' => mb_substr($details, 0, 2000)],
        "check_runs"
    );

    $checkRunBody = array(
        "name" => constant("BOT_CHECK_MESSAGE_PREFIX") . ucwords($type),
        "details_url" => $metadata["dashboardUrl"],
        "status" => "completed",
        "conclusion" => "failure",
        "output" => array(
            "title" => "Checks failed ❌",
            "summary" => "GStraccini checked this " . strtolower($type) . ". Error found!",
            "text" => $details
        )
    );
    patchCheckRun($metadata, $checkRunId, $checkRunBody, "failure");
}
/**
 * Completes a check run as failed because the worker hit an error while it was in progress,
 * so it doesn't stay "in progress" forever. Never throws: it runs while another error is
 * being handled, and that original error is the one to report.
 *
 * @param array      $metadata   Metadata for the GitHub API request (token, checkRunUrl, dashboardUrl).
 * @param int        $checkRunId The ID of the check run in progress.
 * @param string     $type       The check type, used to build the check run name and summary.
 * @param \Throwable $error      The error that interrupted the checks.
 */
function failCheckRunOnError(array $metadata, int $checkRunId, string $type, \Throwable $error): void
{
    try {
        $template = "The bot hit an internal error while checking this %s:\n\n```\n%s: %s\n```";
        $details = sprintf($template, strtolower($type), get_class($error), $error->getMessage());
        setCheckRunFailed($metadata, $checkRunId, $type, mb_substr($details, 0, 60000));
    } catch (\Throwable $e) {
        error_log("[gstraccini-bot] Failed to mark check run {$checkRunId} as failed: {$e->getMessage()}");
        try {
            global $logStream;
            $logStream?->critical(
                'Failed to mark check run #{checkRunId} as failed — it stays in progress: {error}',
                ['checkRunId' => $checkRunId, 'checkRunUrl' => $metadata["checkRunUrl"], 'error' => $e->getMessage(), 'originalError' => $error->getMessage()],
                "check_runs"
            );
        } catch (\Throwable) {
            // Nothing more to do: the error_log line above already has it.
        }
    }
}

/**
 * Creates a new check run in queued status via the GitHub API.
 *
 * @param array  $metadata Metadata array for GitHub check run creation.
 * @param string $commitId A unique identifier for the commit.
 * @param string $type     Check type for queued run.
 *
 * @return int ID of the created check run.
 */
function setCheckRunQueued(array $metadata, string $commitId, string $type): int
{
    $checkRunBody = array(
        "name" => "GStraccini Workflow: ". ucwords($type),
        "details_url" => $metadata["dashboardUrl"],
        "head_sha" => $commitId,
        "status" => "queued",
        "output" => array(
            "title" => "Workflow run queued 🕓",
            "summary" => "Preparing workflow run for " . strtolower($type) . "!",
            "text" => ""
        )
    );

    $response = doRequestGitHub($metadata["token"], $metadata["checkRunUrl"], $checkRunBody, "POST");
    return decodeGitHubResponse($response, "id");
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
    $checkRunBody = array(
        "name" => constant("BOT_CHECK_MESSAGE_PREFIX") . ucwords($type),
        "details_url" => $metadata["dashboardUrl"],
        "status" => "completed",
        "conclusion" => "success",
        "output" => array(
            "title" => "Checks completed ✅",
            "summary" => "GStraccini checked this " . strtolower($type) . " successfully!",
            "text" => $details ?? "No issues found."
        )
    );

    patchCheckRun($metadata, $checkRunId, $checkRunBody, "success");
}

/**
 * Updates a GitHub check run to mark it as completed with an `action_required`
 * conclusion, signaling that the check is blocked on a decision from the user
 * rather than still being computed.
 *
 * @param array $metadata Metadata for the GitHub API request. Must include `checkRunUrl`,
 * `dashboardUrl`, and `token`.
 * @param int $checkRunId The ID of the check run to update.
 * @param string $type The check type, used to build the check run name and summary.
 * @param string $details Explanation of what the user needs to do, shown in the check output.
 */
function setCheckRunActionRequired(array $metadata, int $checkRunId, string $type, string $details): void
{
    $checkRunBody = array(
        "name" => constant("BOT_CHECK_MESSAGE_PREFIX") . ucwords($type),
        "details_url" => $metadata["dashboardUrl"],
        "status" => "completed",
        "conclusion" => "action_required",
        "output" => array(
            "title" => "Action required ⚠️",
            "summary" => "GStraccini needs a decision from you on this " . strtolower($type) . ".",
            "text" => $details
        )
    );

    patchCheckRun($metadata, $checkRunId, $checkRunBody, "action_required");
}
