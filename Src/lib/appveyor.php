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

function requestAppVeyor(string $appVeyorKey, $url, $data = null, $isPut = false): Response
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

    if ($data != null) {
        $response = $isPut
            ? $request->put($url, $headers, json_encode($data))
            : $request->post($url, $headers, json_encode($data));
    } else {
        $response = $request->get($url, $headers);
    }

    if ($response->getStatusCode() >= 300) {
        $info = $response->toJson();
        $logger->log("Invalid AppVeyor response. HTTP Status Code: {$response->getStatusCode()}", $info);
    }

    return $response;
}

function findProjectByRepositorySlug(string $appVeyorKey, $repositorySlug): Object
{
    $searchSlug = strtolower($repositorySlug);

    $projectsResponse = requestAppVeyor($appVeyorKey, "projects");
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
