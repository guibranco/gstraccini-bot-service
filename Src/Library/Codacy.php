<?php

use GuiBranco\Pancake\Logger;
use GuiBranco\Pancake\Request;
use GuiBranco\Pancake\Response;

class Codacy
{
    private $apiToken;
    private $logger;
    private $headers;
    private $baseUrl = "https://api.codacy.com/api/v3/";

    /**
     * Codacy constructor.
     *
     * @param string $apiToken The API token for authentication.
     * @param Logger $logger An instance of a Logger for logging purposes.
     */
    public function __construct(string $apiToken, Logger $logger)
    {
        $this->apiToken = $apiToken;
        $this->logger = $logger;
        $this->headers = [
            constant("USER_AGENT"),
            "Accept: application/json",
            "Content-Type: application/json",
            "api-token: {$this->apiToken}"
        ];
    }

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
     * @return Response The function `bypassPullRequestAnalysis` is returning an object of type `Response`,
     * which is the response from the Codacy API after attempting to bypass the pull request analysis for a
     * specific repository in a remote organization.
     */
    public function bypassPullRequestAnalysis(string $orgName, string $repositoryName, string $pullRequestNumber): Response
    {
        $url = "{$this->baseUrl}analysis/organizations/gh/{$remoteOrganizationName}/repositories/{$repositoryName}/pull-requests/{$pullRequestNumber}/bypass";
        $request = new Request();
        $response = $request->post($url, $this->headers);

        if ($response->getStatusCode() >= 300) {
            $info = $response->toJson();
            $this->logger->log("Invalid Codacy response. HTTP Status Code: {$response->getStatusCode()}", $info);
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
     * @return Response The function `reanalyzeCommit` returns an object of type `Response`, which is the
     * response from the Codacy API after attempting to reanalyze the specified commit in a repository for
     * a given organization.
     */
    public function reanalyzeCommit(string $remoteOrganizationName, string $repositoryName, string $commitUUID): Response
    {
        $url = "{$this->baseUrl}analysis/organizations/gh/{$remoteOrganizationName}/repositories/{$repositoryName}/commit/{$commitUUID}/reanalyze";
        $request = new Request();
        $response = $request->post($url, $this->headers);

        if ($response->getStatusCode() >= 300) {
            $info = $response->toJson();
            $this->logger->log("Invalid Codacy response. HTTP Status Code: {$response->getStatusCode()}", $info);
        }

        return $response;
    }
}
