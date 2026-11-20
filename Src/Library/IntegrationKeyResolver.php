<?php

namespace GuiBranco\GStracciniBot\Library;

use Exception;
use RuntimeException;
use stdClass;

/**
 * Finds the API key a user stored for a provider (GitHub, AppVeyor, ...) to
 * act on an installation's repositories.
 *
 * The key comes from the user's integration for the provider (stored
 * encrypted in `user_integrations`, added through the integrations API), for
 * a user mapped to the installation in `user_installations`. When several
 * users of the installation have one, the user whose login is the repository
 * owner wins, then the most recently updated integration.
 */
class IntegrationKeyResolver
{

    /**
     * The `user_integrations.Provider` value looked up.
     *
     * @var string
     */
    protected string $provider;

    /**
     * The LogStream category used for this resolver's logs.
     *
     * @var string
     */
    protected string $logCategory;

    /**
     * Decrypts stored keys; built from the configured key on first use when not given.
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
     * Sets up the resolver.
     *
     * @param string            $provider    The `user_integrations.Provider` value to look up.
     * @param CryptoHelper|null $crypto      Decrypts stored keys; null to use the configured key.
     * @param string            $logCategory The LogStream category for this resolver's logs.
     *
     * @return void
     */
    public function __construct(string $provider, ?CryptoHelper $crypto, string $logCategory)
    {
        $this->provider = $provider;
        $this->crypto = $crypto;
        $this->cryptoLoaded = ($crypto !== null);
        $this->logCategory = $logCategory;

    }//end __construct()


    /**
     * Finds the stored key of the installation's best candidate.
     *
     * A database failure is logged and treated as no key stored, so the
     * caller can decide what to do without one.
     *
     * @param int    $installationId  The installation the repository belongs to.
     * @param string $repositoryOwner The login owning the repository, preferred among the installation's users.
     *
     * @return IntegrationKey|null Null when no usable key is stored.
     */
    public function find(int $installationId, string $repositoryOwner): ?IntegrationKey
    {
        global $logStream;

        try {
            return $this->fromDatabase($installationId, $repositoryOwner);
        } catch (Exception $exception) {
            $context = [];
            $context["provider"] = $this->provider;
            $context["installationId"] = $installationId;
            $context["error"] = $exception->getMessage();
            $logStream?->error("Unable to read the stored {provider} integration for installation #{installationId}: {error}", $context, $this->logCategory);
        }

        return null;

    }//end find()


    /**
     * Reads and decrypts the stored key of the installation's best candidate.
     *
     * @param int    $installationId  The installation the repository belongs to.
     * @param string $repositoryOwner The login owning the repository.
     *
     * @return IntegrationKey|null Null when no usable key is stored.
     */
    private function fromDatabase(int $installationId, string $repositoryOwner): ?IntegrationKey
    {
        $candidate = $this->findCandidate($installationId, $repositoryOwner);
        if ($candidate === null) {
            return null;
        }

        $key = $this->decrypt($candidate, $installationId);
        if ($key === null) {
            return null;
        }

        $this->recordUsage((int) $candidate->userId);
        return new IntegrationKey($key, (string) $candidate->login, (int) $candidate->userId);

    }//end fromDatabase()


    /**
     * Decrypts a candidate's key, recording on the integration why it can't be used.
     *
     * @param stdClass $candidate      The candidate returned by findCandidate().
     * @param int      $installationId The installation, for the logs.
     *
     * @return string|null The key, or null when it can't be decrypted.
     */
    private function decrypt(stdClass $candidate, int $installationId): ?string
    {
        global $logStream;

        $context = [];
        $context["provider"] = $this->provider;
        $context["login"] = $candidate->login;
        $context["installationId"] = $installationId;

        $crypto = $this->getCrypto();
        if ($crypto === null) {
            $logStream?->warning("{provider} integration of {login} not used for installation #{installationId}: encryption is not configured", $context, $this->logCategory);
            return null;
        }

        try {
            return $crypto->decrypt((string) $candidate->encryptedToken);
        } catch (RuntimeException $exception) {
            $context["error"] = $exception->getMessage();
            $this->recordError((int) $candidate->userId, (string) $candidate->encryptedToken, "Unable to decrypt the stored token");
            $logStream?->error("{provider} integration of {login} not used for installation #{installationId}: {error}", $context, $this->logCategory);
        }

        return null;

    }//end decrypt()


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
            // Without a key no stored value can be read.
            $this->crypto = null;
        }

        return $this->crypto;

    }//end getCrypto()


    /**
     * Reads the best stored integration among the installation's active users.
     *
     * @param int    $installationId  The installation the repository belongs to.
     * @param string $repositoryOwner The login owning the repository, preferred.
     *
     * @return stdClass|null The candidate (userId, login, encryptedToken), or null when none is stored.
     */
    protected function findCandidate(int $installationId, string $repositoryOwner): ?stdClass
    {
        $provider = $this->provider;
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
     * Records that a user's stored key was just used.
     *
     * @param int $userId The user whose integration was used.
     *
     * @return void
     */
    protected function recordUsage(int $userId): void
    {
        $provider = $this->provider;
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("UPDATE user_integrations SET LastUsedAt = NOW() WHERE UserId = ? AND Provider = ?");
        $stmt->bind_param("is", $userId, $provider);
        $stmt->execute();
        $stmt->close();
        $mysqli->close();

    }//end recordUsage()


    /**
     * Marks a user's stored key as unusable, with the reason. Only the key
     * that failed is marked: one replaced in the meantime is left as it is.
     *
     * @param int    $userId         The user whose integration failed.
     * @param string $encryptedToken The stored value that failed.
     * @param string $error          Why the key can't be used.
     *
     * @return void
     */
    protected function recordError(int $userId, string $encryptedToken, string $error): void
    {
        $provider = $this->provider;
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("UPDATE user_integrations SET Status = 'Error', LastError = ? WHERE UserId = ? AND Provider = ? AND EncryptedApiKey = ?");
        $stmt->bind_param("siss", $error, $userId, $provider, $encryptedToken);
        $stmt->execute();
        $stmt->close();
        $mysqli->close();

    }//end recordError()


}//end class
