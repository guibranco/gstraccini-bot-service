<?php

use GuiBranco\Pancake\Request;

/**
 * The function bypassPullRequestAnalysis sends a POST request to Codacy API to bypass analysis for a
 * specific pull request in a repository.
 *
 * @param string remoteOrganizationName The `remoteOrganizationName` parameter represents the name of
 * the organization on the remote repository hosting service (e.g., GitHub) where the repository is
 * located. This could be the GitHub organization name if the repository is hosted on GitHub.
 * @param string repositoryName The `repositoryName` parameter in the `bypassPullRequestAnalysis`
 * function refers to the name of the repository for which you want to bypass the pull request analysis
 * on Codacy. This parameter should be a string that represents the name of the repository on Codacy
 * where the pull request is located.
 * @param string pullRequestNumber The `pullRequestNumber` parameter in the `bypassPullRequestAnalysis`
 * function represents the number assigned to a specific pull request in a repository. This number is
 * used to uniquely identify and reference the pull request when interacting with the repository, such
 * as requesting code analysis or making changes to the pull request
 *
 * @return stdClass The function `bypassPullRequestAnalysis` is returning an object of type `stdClass`,
 * which is the response from the Codacy API after attempting to bypass the pull request analysis for a
 * specific repository in a remote organization.
 */
function bypassPullRequestAnalysis(string $remoteOrganizationName, string $repositoryName, string $pullRequestNumber): stdClass
{
    global $codacyApiToken, $logger;

    $baseUrl = "https://api.codacy.com/api/v3/";
    $url = "analysis/organizations/gh/{$remoteOrganizationName}/repositories/{$repositoryName}/pull-requests/{$pullRequestNumber}/bypass";

    $headers = array(
        constant("USER_AGENT"),
        "api-token: " . $codacyApiToken,
        "Accept: application/json"
    );

    $request = new Request();

    $response = $request->post($baseUrl . $url, $headers);

    if ($response->statusCode >= 300) {
        $info = json_encode(array("url" => $url, "response" => $response));
        $logger->log("Error on Codacy request (bypass)", $info);
    }

    return $response;
}


/**
 * The function reanalyzeCommit sends a POST request to the Codacy API to reanalyze a specific commit
 * in a repository.
 *
 * @param string remoteOrganizationName The `remoteOrganizationName` parameter represents the name of
 * the organization on the remote repository hosting service (e.g., GitHub) where the repository is
 * located. This could be the GitHub organization name if the repository is hosted on GitHub.
 * @param string repositoryName The `repositoryName` parameter in the `reanalyzeCommit` function refers
 * to the name of the repository for which you want to reanalyze a specific commit on Codacy. This
 * parameter should be a string that represents the name of the repository on Codacy.
 * @param string commitUUID The `commitUUID` parameter represents the unique identifier (UUID) of the
 * commit that you want Codacy to reanalyze. This UUID uniquely identifies the commit in the
 * repository.
 *
 * @return stdClass The function `reanalyzeCommit` returns an object of type `stdClass`, which is the
 * response from the Codacy API after attempting to reanalyze the specified commit in a repository for
 * a given organization.
 */
function reanalyzeCommit(string $remoteOrganizationName, string $repositoryName, string $commitUUID): stdClass
{
    global $codacyApiToken, $logger;

    $baseUrl = "https://api.codacy.com/api/v3/";
    $url = "organizations/gh/{$remoteOrganizationName}/repositories/{$repositoryName}/reanalyzeCommit";

    $headers = array(
        constant("USER_AGENT"),
        "api-token: " . $codacyApiToken,
        "Accept: application/json"
    );

    $request = new Request();

    $response = $request->post($baseUrl . $url, $headers, array("commit" => $commitUUID, "cleanCache" => false));

    if ($response->statusCode >= 300) {
        $info = json_encode(array("url" => $url, "response" => $response));
        $logger->log("Error on Codacy request (reanalyze)", $info);
    }

    return $response;
}
