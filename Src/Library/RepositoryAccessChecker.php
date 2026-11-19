<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\Response;

/**
 * Checks what a comment's sender may reach in another repository through the
 * bot. Installation tokens cover every repository of the installation, so a
 * command that names another repository (`copy issue`, `copy labels`) must not
 * rely on the token's access alone: the sender needs access too.
 */
class RepositoryAccessChecker
{
    /**
     * The status GitHub answers when the login is a collaborator of the repository.
     */
    private const COLLABORATOR_STATUS = 204;

    /**
     * An `owner/name` pair as GitHub allows them.
     */
    private const REPOSITORY_PATTERN = "/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/";

    /**
     * The installation token the checks are made with.
     *
     * @var string
     */
    private string $token;


    /**
     * Sets up the checker.
     *
     * @param string $token The installation token the checks are made with.
     *
     * @return void
     */
    public function __construct(string $token)
    {
        $this->token = $token;

    }//end __construct()


    /**
     * Whether the login is a collaborator of the repository.
     *
     * @param string $repository The repository, as `owner/name`.
     * @param string $login      The GitHub login.
     *
     * @return bool False for a malformed repository, or one the installation can't see.
     */
    public function isCollaborator(string $repository, string $login): bool
    {
        if ($this->isValidRepository($repository) === false) {
            return false;
        }

        $response = $this->get("repos/{$repository}/collaborators/{$login}");
        return $response->getStatusCode() === self::COLLABORATOR_STATUS;

    }//end isCollaborator()


    /**
     * Whether the repository is public.
     *
     * @param string $repository The repository, as `owner/name`.
     *
     * @return bool False for a private, malformed or unreachable repository.
     */
    public function isPublic(string $repository): bool
    {
        if ($this->isValidRepository($repository) === false) {
            return false;
        }

        $response = $this->get("repos/{$repository}");
        $decoded = json_decode((string) $response->getBody());
        if ($response->getStatusCode() !== 200 || is_object($decoded) === false) {
            return false;
        }

        $private = ($decoded->private ?? true);
        return $private === false;

    }//end isPublic()


    /**
     * Whether the login may have the bot read the repository's labels: anyone
     * can for a public repository, only collaborators for a private one.
     *
     * @param string $repository The repository, as `owner/name`.
     * @param string $login      The GitHub login.
     *
     * @return bool True when the labels may be read for the login.
     */
    public function canReadLabels(string $repository, string $login): bool
    {
        if ($this->isPublic($repository) === true) {
            return true;
        }

        return $this->isCollaborator($repository, $login);

    }//end canReadLabels()


    /**
     * Sends a GET request with the installation token; a 404 is an expected answer.
     *
     * @param string $url The endpoint, relative to the API root.
     *
     * @return Response The response.
     */
    protected function get(string $url): Response
    {
        return doRequestGitHubExpecting($this->token, $url, "GET", [404]);

    }//end get()


    /**
     * Whether the repository is an `owner/name` pair with no path traversal.
     *
     * @param string $repository The repository, as `owner/name`.
     *
     * @return bool True when it can be put in an API path.
     */
    private function isValidRepository(string $repository): bool
    {
        if (preg_match(self::REPOSITORY_PATTERN, $repository) !== 1) {
            return false;
        }

        return str_contains($repository, "..") === false;

    }//end isValidRepository()


}//end class
