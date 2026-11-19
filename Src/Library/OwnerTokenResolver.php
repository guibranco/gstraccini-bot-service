<?php

namespace GuiBranco\GStracciniBot\Library;

use RuntimeException;
use stdClass;

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
class OwnerTokenResolver
{
    /**
     * The `user_integrations.Provider` value holding a user's GitHub token.
     */
    public const PROVIDER = "GitHub";

    /**
     * Decrypts stored tokens; built from the configured key on first use when not given.
     *
     * @var CryptoHelper|null
     */
    private ?CryptoHelper $crypto;

    /**
     * Whether building the CryptoHelper was already attempted.
     *
     * @var boolean
     */
    private bool $cryptoLoaded;

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
        $this->crypto = $crypto;
        $this->cryptoLoaded = ($crypto !== null);
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
        $stored = $this->fromDatabase($installationId, $repositoryOwner);
        if ($stored !== null) {
            return $stored;
        }

        return $this->fallback();

    }//end resolve()


    /**
     * Reads and decrypts the stored token of the installation's best candidate.
     *
     * @param int    $installationId  The installation the repository belongs to.
     * @param string $repositoryOwner The login owning the repository.
     *
     * @return OwnerCredentials|null Null when no usable token is stored.
     */
    private function fromDatabase(int $installationId, string $repositoryOwner): ?OwnerCredentials
    {
        $candidate = $this->findCandidate($installationId, $repositoryOwner);
        if ($candidate === null) {
            return null;
        }

        $token = $this->decrypt($candidate, $installationId);
        if ($token === null) {
            return null;
        }

        $this->recordUsage((int) $candidate->userId);
        return new OwnerCredentials($token, (string) $candidate->login, OwnerCredentials::SOURCE_DATABASE);

    }//end fromDatabase()


    /**
     * Decrypts a candidate's token, recording on the integration why it can't be used.
     *
     * @param stdClass $candidate      The candidate returned by findCandidate().
     * @param int      $installationId The installation, for the logs.
     *
     * @return string|null The token, or null when it can't be decrypted.
     */
    private function decrypt(stdClass $candidate, int $installationId): ?string
    {
        global $logStream;

        $context = [];
        $context["login"] = $candidate->login;
        $context["installationId"] = $installationId;

        $crypto = $this->getCrypto();
        if ($crypto === null) {
            $logStream?->warning("GitHub integration of {login} not used for installation #{installationId}: encryption is not configured", $context, "owner_token");
            return null;
        }

        try {
            return $crypto->decrypt((string) $candidate->encryptedToken);
        } catch (RuntimeException $exception) {
            $context["error"] = $exception->getMessage();
            $this->recordError((int) $candidate->userId, "Unable to decrypt the stored token");
            $logStream?->error("GitHub integration of {login} not used for installation #{installationId}: {error}", $context, "owner_token");
        }

        return null;

    }//end decrypt()


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


    /**
     * Builds the CryptoHelper from the configured key on first use.
     *
     * @return CryptoHelper|null Null when encryption isn't configured.
     */
    private function getCrypto(): ?CryptoHelper
    {
        if ($this->cryptoLoaded === true) {
            return $this->crypto;
        }

        $this->cryptoLoaded = true;
        try {
            $this->crypto = new CryptoHelper(null);
        } catch (RuntimeException $exception) {
            // Without a key no stored token can be read, so the fallback is used.
            $this->crypto = null;
        }

        return $this->crypto;

    }//end getCrypto()


    /**
     * Reads the best stored GitHub integration among the installation's active users.
     *
     * @param int    $installationId  The installation the repository belongs to.
     * @param string $repositoryOwner The login owning the repository, preferred.
     *
     * @return stdClass|null The candidate (userId, login, encryptedToken), or null when none is stored.
     */
    protected function findCandidate(int $installationId, string $repositoryOwner): ?stdClass
    {
        $provider = self::PROVIDER;
        $sql = "SELECT ui.UserId AS userId, gu.Login AS login, ui.EncryptedApiKey AS encryptedToken
                FROM user_installations uins
                INNER JOIN user_integrations ui
                    ON ui.UserId = uins.UserId AND ui.Provider = ? AND ui.Status = 'Validated'
                INNER JOIN github_users gu
                    ON gu.Sequence = (SELECT MAX(Sequence) FROM github_users WHERE UserId = uins.UserId)
                WHERE uins.InstallationId = ? AND uins.RemovedAt IS NULL
                ORDER BY (gu.Login = ?) DESC, ui.UpdatedAt DESC
                LIMIT 1";

        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sis", $provider, $installationId, $repositoryOwner);
        if ($stmt->execute() === false) {
            $failure = statementFailed($stmt);
            $stmt->close();
            $mysqli->close();
            throw $failure;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_object();
        $result->close();
        $stmt->close();
        $mysqli->close();

        if ($row instanceof stdClass) {
            return $row;
        }

        return null;

    }//end findCandidate()


    /**
     * Records that a user's stored token was just used.
     *
     * @param int $userId The user whose integration was used.
     *
     * @return void
     */
    protected function recordUsage(int $userId): void
    {
        $provider = self::PROVIDER;
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("UPDATE user_integrations SET LastUsedAt = NOW() WHERE UserId = ? AND Provider = ?");
        $stmt->bind_param("is", $userId, $provider);
        $stmt->execute();
        $stmt->close();
        $mysqli->close();

    }//end recordUsage()


    /**
     * Marks a user's stored token as unusable, with the reason.
     *
     * @param int    $userId The user whose integration failed.
     * @param string $error  Why the token can't be used.
     *
     * @return void
     */
    protected function recordError(int $userId, string $error): void
    {
        $provider = self::PROVIDER;
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("UPDATE user_integrations SET Status = 'Error', LastError = ? WHERE UserId = ? AND Provider = ?");
        $stmt->bind_param("sis", $error, $userId, $provider);
        $stmt->execute();
        $stmt->close();
        $mysqli->close();

    }//end recordError()


}//end class
