<?php

use GuiBranco\Pancake\Request;
use GuiBranco\Pancake\Response;

function requestAppVeyor($url, $data = null, $isPut = false): Response
{
    global $appVeyorKey, $logger;

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
        die("Invalid AppVeyor response.\n" . $response->toJson());
    }

    return $response;
}

function findProjectByRepositorySlug($repositorySlug): Object
{
    $searchSlug = strtolower($repositorySlug);

    $projectsResponse = requestAppVeyor("projects");
    if ($projectsResponse == null) {
        return null;
    }

    $projects = json_decode($projectsResponse->body);
    if (isset($projects->message) && !empty($projects->message)) {
        $error = new \stdClass();
        $error->error = true;
        $error->message = $projects->message;
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
