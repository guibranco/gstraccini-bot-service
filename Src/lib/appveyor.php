<?php

use GuiBranco\GStracciniBot\Library\IntegrationKeyResolver;
use GuiBranco\Pancake\Request;
use GuiBranco\Pancake\Response;

/**
 * The `user_integrations.Provider` value holding a user's AppVeyor API key.
 */
const APPVEYOR_PROVIDER = "AppVeyor";


/**
 * Finds the AppVeyor API key stored by a user of the installation (the
 * repository owner first), in the `AppVeyor` integration of `user_integrations`.
 *
 * @param int    $installationId  The installation the repository belongs to.
 * @param string $repositoryOwner The login owning the repository.
 *
 * @return string|null Null when no user of the installation has stored a usable key.
 */
function findAppVeyorKey(int $installationId, string $repositoryOwner): ?string
{
    $integration = (new IntegrationKeyResolver(APPVEYOR_PROVIDER, null, "appveyor"))->find($installationId, $repositoryOwner);
    return $integration?->key;
}


/**
 * Sends a request to the AppVeyor API.
 *
 * @param string     $appVeyorKey The AppVeyor API key to authenticate with.
 * @param string     $method      The HTTP method: GET, POST or PUT.
 * @param string     $url         The endpoint, relative to the API base URL.
 * @param array|null $data        The JSON payload of a POST or PUT, null for a GET.
 *
 * @return Response The AppVeyor response.
 */
function requestAppVeyor(string $appVeyorKey, string $method, string $url, ?array $data): Response
{
    global $logger;

    $baseUrl = "https://ci.appveyor.com/api/";
    $url = $baseUrl . $url;

    $headers = array(
        constant("USER_AGENT"),
        "Authorization: Bearer " . $appVeyorKey,
        "Content-Type: application/json"
    );

    $request = new Request();

    $response = null;

    if ($method === "GET") {
        $response = $request->get($url, $headers);
    }

    if ($method === "POST") {
        $response = $request->post($url, $headers, json_encode($data));
    }

    if ($method === "PUT") {
        $response = $request->put($url, $headers, json_encode($data));
    }

    if ($response->getStatusCode() >= 300) {
        $info = $response->toJson();
        $logger->log("Invalid AppVeyor response. HTTP Status Code: {$response->getStatusCode()}", $info);
    }

    return $response;
}


/**
 * Finds the AppVeyor project built from a GitHub repository.
 *
 * @param string $appVeyorKey    The AppVeyor API key to authenticate with.
 * @param string $repositorySlug The repository, as "owner/name".
 *
 * @return object The project, or an object whose `error` is true and `message` explains why.
 */
function findProjectByRepositorySlug(string $appVeyorKey, string $repositorySlug): object
{
    $searchSlug = strtolower($repositorySlug);

    $projectsResponse = requestAppVeyor($appVeyorKey, "GET", "projects", null);
    if ($projectsResponse == null) {
        return null;
    }

    $projects = json_decode($projectsResponse->getBody());
    $isFailure = ($projectsResponse->getStatusCode() >= 300 || is_array($projects) === false);
    if ($isFailure === true) {
        $error = new \stdClass();
        $error->error = true;
        $error->message = ($projects->message ?? "AppVeyor projects lookup failed.");
        $error->statusCode = $projectsResponse->getStatusCode();
        $error->body = (string) $projectsResponse->getBody();
        return $error;
    }

    $projects = array_filter($projects, function ($p) use ($searchSlug) {
        return $searchSlug === strtolower($p->repositoryName);
    });
    $projects = array_values($projects);

    $result = $projects[0];
    $result->error = false;

    return $result;
}
