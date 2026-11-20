<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Finds the user token to act as a person on an installation's repositories.
 *
 * The token comes from the database: the `GitHub` integration (stored
 * encrypted in `user_integrations`, added through the integrations API) of a
 * user mapped to the installation in `user_installations`. When several users
 * of the installation have one, the user whose login is the repository owner
 * wins, then the most recently updated integration.
 *
 * While no user of the installation has stored a token, the service-wide
 * `$gitHubUserToken` secret (with `ownerHandler` from the config as its login)
 * is used instead, so nothing changes until a token is stored. Once every
 * owner has stored theirs, the USER_TOKEN secret can be removed.
 */
class OwnerTokenResolver extends IntegrationKeyResolver
{
    /**
     * The `user_integrations.Provider` value holding a user's GitHub token.
     */
    public const PROVIDER = "GitHub";

    /**
     * The service-wide token; the global `$gitHubUserToken` when null.
     *
     * @var string|null
     */
    private ?string $fallbackToken;

    /**
     * The login of the fallback token; `ownerHandler` from the config when null.
     *
     * @var string|null
     */
    private ?string $fallbackLogin;


    /**
     * Sets up the resolver. Pass nulls to use the configured key, secret and owner.
     *
     * @param CryptoHelper|null $crypto        Decrypts stored tokens.
     * @param string|null       $fallbackToken The service-wide token.
     * @param string|null       $fallbackLogin The login of the service-wide token.
     *
     * @return void
     */
    public function __construct(?CryptoHelper $crypto, ?string $fallbackToken, ?string $fallbackLogin)
    {
        parent::__construct(self::PROVIDER, $crypto, "owner_token");
        $this->fallbackToken = $fallbackToken;
        $this->fallbackLogin = $fallbackLogin;

    }//end __construct()


    /**
     * Resolves the owner credentials for an installation.
     *
     * @param int    $installationId  The installation the repository belongs to.
     * @param string $repositoryOwner The login owning the repository, preferred among the installation's users.
     *
     * @return OwnerCredentials|null Null when neither a stored token nor the fallback is available.
     */
    public function resolve(int $installationId, string $repositoryOwner): ?OwnerCredentials
    {
        // A database failure must not stop the pull request: find() returns null and the fallback is tried instead.
        $stored = $this->find($installationId, $repositoryOwner);
        if ($stored !== null) {
            return new OwnerCredentials($stored->key, $stored->login, OwnerCredentials::SOURCE_DATABASE);
        }

        return $this->fallback();

    }//end resolve()


    /**
     * The service-wide token, while no user of the installation has stored one.
     *
     * @return OwnerCredentials|null Null when the secret or the owner login isn't configured.
     */
    private function fallback(): ?OwnerCredentials
    {
        global $gitHubUserToken;

        $token = ($this->fallbackToken ?? $gitHubUserToken ?? "");
        $login = $this->fallbackLogin;
        if ($login === null) {
            $login = (loadConfig()->ownerHandler ?? "");
        }

        if (empty($token) === true || empty($login) === true) {
            return null;
        }

        return new OwnerCredentials($token, $login, OwnerCredentials::SOURCE_FALLBACK);

    }//end fallback()


}//end class
