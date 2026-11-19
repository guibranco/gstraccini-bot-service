<?php

namespace GuiBranco\GStracciniBot\Library;

use RuntimeException;

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
    /** The `user_integrations.Provider` value holding a user's GitHub token. */
    public const PROVIDER = "GitHub";

    private ?CryptoHelper $crypto;

    private bool $cryptoResolved;

    private ?string $fallbackToken;

    private ?string $fallbackLogin;

    /**
     * @param CryptoHelper|null $crypto        Decrypts stored tokens; built from the configured key when null.
     * @param string|null       $fallbackToken The service-wide token; defaults to the global `$gitHubUserToken`.
     * @param string|null       $fallbackLogin The login of the fallback token; defaults to `ownerHandler` from the config.
     */
    public function __construct(?CryptoHelper $crypto = null, ?string $fallbackToken = null, ?string $fallbackLogin = null)
    {
        $this->crypto = $crypto;
        $this->cryptoResolved = $crypto !== null;
        $this->fallbackToken = $fallbackToken;
        $this->fallbackLogin = $fallbackLogin;
    }

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
    }

    private function fromDatabase(int $installationId, string $repositoryOwner): ?OwnerCredentials
    {
        global $logStream;

        $candidate = $this->findCandidate($installationId, $repositoryOwner);
        if ($candidate === null) {
            return null;
        }

        $crypto = $this->crypto();
        if ($crypto === null) {
            $logStream?->warning(
                'GitHub integration of {login} not used for installation #{installationId}: encryption is not configured',
                ["login" => $candidate["login"], "installationId" => $installationId],
                "owner_token"
            );
            return null;
        }

        try {
            $token = $crypto->decrypt($candidate["encryptedToken"]);
        } catch (RuntimeException $e) {
            $this->recordError((int) $candidate["userId"], "Unable to decrypt the stored token");
            $logStream?->error(
                'GitHub integration of {login} not used for installation #{installationId}: {error}',
                ["login" => $candidate["login"], "installationId" => $installationId, "error" => $e->getMessage()],
                "owner_token"
            );
            return null;
        }

        $this->recordUsage((int) $candidate["userId"]);

        return new OwnerCredentials($token, (string) $candidate["login"], OwnerCredentials::SOURCE_DATABASE);
    }

    private function fallback(): ?OwnerCredentials
    {
        global $gitHubUserToken;

        $token = $this->fallbackToken ?? $gitHubUserToken ?? null;
        if (empty($token)) {
            return null;
        }

        $login = $this->fallbackLogin ?? (loadConfig()->ownerHandler ?? null);
        if (empty($login)) {
            return null;
        }

        return new OwnerCredentials($token, $login, OwnerCredentials::SOURCE_FALLBACK);
    }

    private function crypto(): ?CryptoHelper
    {
        if ($this->cryptoResolved === false) {
            $this->cryptoResolved = true;
            try {
                $this->crypto = new CryptoHelper();
            } catch (RuntimeException $e) {
                $this->crypto = null;
            }
        }

        return $this->crypto;
    }

    /**
     * Reads the best stored GitHub integration among the installation's
     * active users.
     *
     * @return array{userId: int|string, login: string, encryptedToken: string}|null
     */
    protected function findCandidate(int $installationId, string $repositoryOwner): ?array
    {
        $mysqli = connectToDatabase();
        $sql = "SELECT ui.UserId AS userId, gu.Login AS login, ui.EncryptedApiKey AS encryptedToken
                FROM user_installations uins
                INNER JOIN user_integrations ui
                    ON ui.UserId = uins.UserId AND ui.Provider = ? AND ui.Status = 'Validated'
                INNER JOIN github_users gu
                    ON gu.Sequence = (SELECT MAX(Sequence) FROM github_users WHERE UserId = uins.UserId)
                WHERE uins.InstallationId = ? AND uins.RemovedAt IS NULL
                ORDER BY (gu.Login = ?) DESC, ui.UpdatedAt DESC
                LIMIT 1";
        $stmt = $mysqli->prepare($sql);
        $provider = self::PROVIDER;
        $stmt->bind_param("sis", $provider, $installationId, $repositoryOwner);
        if ($stmt->execute() === false) {
            $exception = statementFailed($stmt);
            $stmt->close();
            $mysqli->close();
            throw $exception;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $result->close();
        $stmt->close();
        $mysqli->close();

        return $row ?: null;
    }

    protected function recordUsage(int $userId): void
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("UPDATE user_integrations SET LastUsedAt = NOW() WHERE UserId = ? AND Provider = ?");
        $provider = self::PROVIDER;
        $stmt->bind_param("is", $userId, $provider);
        $stmt->execute();
        $stmt->close();
        $mysqli->close();
    }

    protected function recordError(int $userId, string $error): void
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("UPDATE user_integrations SET Status = 'Error', LastError = ? WHERE UserId = ? AND Provider = ?");
        $provider = self::PROVIDER;
        $stmt->bind_param("sis", $error, $userId, $provider);
        $stmt->execute();
        $stmt->close();
        $mysqli->close();
    }
}
