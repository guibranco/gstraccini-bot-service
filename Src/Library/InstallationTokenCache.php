<?php

namespace GuiBranco\GStracciniBot\Library;

use Exception;
use RuntimeException;
use stdClass;

/**
 * Reuses GitHub App installation access tokens until they expire, instead of
 * minting a new one (`POST /app/installations/{id}/access_tokens`) per operation.
 *
 * GitHub tokens live for one hour. A token is served from, in order:
 *  1. the current PHP process (a run handles many items in a loop);
 *  2. APCu, when the extension is loaded and enabled (shared between the
 *     requests served by the same PHP pool on the cPanel host);
 *  3. the `github_installation_tokens` table, encrypted with CryptoHelper
 *     (shared by every process and host using the same database);
 * and only then minted, the new token being written back to every layer.
 *
 * A token is considered expired SAFETY_MARGIN_SECONDS before GitHub's
 * `expires_at`, so an operation never starts with a token about to lapse.
 *
 * Every layer is best effort: an APCu or database failure is logged and the
 * next layer (eventually minting) is used, so caching never stops an item.
 * Without an encryption key the database layer is skipped: tokens are never
 * stored in plain text in the database.
 */
class InstallationTokenCache
{
    /**
     * Seconds before GitHub's expiry at which a cached token stops being served.
     */
    public const SAFETY_MARGIN_SECONDS = 300;

    /**
     * Prefix of the APCu keys, so they can't clash with other apps in the pool.
     */
    private const APCU_PREFIX = "gstraccini:installation-token:";

    /**
     * The log category.
     */
    private const LOG_CATEGORY = "installation_token";

    /**
     * The instance used by the global token functions in lib/github.php.
     *
     * @var InstallationTokenCache|null
     */
    private static ?InstallationTokenCache $shared = null;

    /**
     * Tokens held by this process, keyed by cache key.
     *
     * @var array<string, array{token: string, expiresAt: int}>
     */
    private array $memory = [];

    /**
     * Cache key of each token handed out by this process, keyed by token, so a
     * token GitHub rejects can be evicted from every layer.
     *
     * @var array<string, string>
     */
    private array $keysByToken = [];

    /**
     * Encrypts the tokens stored in the database; null skips the database layer.
     *
     * @var CryptoHelper|null
     */
    private ?CryptoHelper $crypto;

    /**
     * Whether the APCu layer is used.
     *
     * @var boolean
     */
    private bool $apcuEnabled;

    /**
     * Returns the current Unix time; replaceable in tests.
     *
     * @var callable
     */
    private $clock;


    /**
     * Sets up the cache.
     *
     * @param CryptoHelper|null $crypto      Encrypts stored tokens; null skips the database layer.
     * @param bool|null         $apcuEnabled Whether to use APCu; null detects the extension.
     * @param callable|null     $clock       Returns the current Unix time; null uses time().
     *
     * @return void
     */
    public function __construct(?CryptoHelper $crypto, ?bool $apcuEnabled = null, ?callable $clock = null)
    {
        $this->crypto = $crypto;
        $this->apcuEnabled = ($apcuEnabled ?? self::isApcuAvailable());
        $this->clock = ($clock ?? "time");

    }//end __construct()


    /**
     * The instance shared by the whole process, built from the configured encryption key.
     *
     * @return InstallationTokenCache The shared cache.
     */
    public static function shared(): InstallationTokenCache
    {
        if (self::$shared === null) {
            self::$shared = new InstallationTokenCache(self::buildCrypto());
        }

        return self::$shared;

    }//end shared()


    /**
     * Replaces (or, with null, resets) the shared instance; used by tests.
     *
     * @param InstallationTokenCache|null $cache The instance to share.
     *
     * @return void
     */
    public static function setShared(?InstallationTokenCache $cache): void
    {
        self::$shared = $cache;

    }//end setShared()


    /**
     * The cache key of a token request: the installation and the permissions
     * asked. Tokens cover every repository of the installation, so one token
     * per installation (and permission set) is shared by all its repositories.
     *
     * @param string     $installationId The installation.
     * @param array|null $permissions    The permissions asked, or null for the app's.
     *
     * @return string A 64-character hex key.
     */
    public static function buildKey(string $installationId, ?array $permissions): string
    {
        $normalizedPermissions = ($permissions ?? []);
        ksort($normalizedPermissions);

        $parts = [];
        $parts[] = $installationId;
        $parts[] = json_encode($normalizedPermissions);

        return hash("sha256", implode("|", $parts));

    }//end buildKey()


    /**
     * Returns a cached, still-valid token for the key, or mints one.
     *
     * @param string   $key            The key from buildKey().
     * @param int      $installationId The installation, stored for housekeeping.
     * @param callable $mint           Mints a token; returns array{token: string, expiresAt: int}.
     *
     * @return string The token.
     */
    public function getOrMint(string $key, int $installationId, callable $mint): string
    {
        $cached = $this->fromMemory($key);
        if ($cached === null) {
            $cached = $this->fromApcu($key);
        }

        if ($cached === null) {
            $cached = $this->fromDatabase($key);
        }

        if ($cached === null) {
            $cached = $mint();
            $this->validateMinted($cached);
            $this->toApcu($key, $cached);
            $this->toDatabase($key, $installationId, $cached);
        }

        $this->memory[$key] = $cached;
        $this->keysByToken[$cached["token"]] = $key;

        return $cached["token"];

    }//end getOrMint()


    /**
     * Evicts a token GitHub rejected (revoked early: app uninstalled, permissions
     * changed, repository removed), so the next operation mints a new one.
     * Tokens this process didn't hand out are ignored.
     *
     * @param string $token The rejected token.
     *
     * @return void
     */
    public function invalidateToken(string $token): void
    {
        $key = ($this->keysByToken[$token] ?? null);
        if ($key === null) {
            return;
        }

        unset($this->keysByToken[$token]);
        $this->forget($key);

    }//end invalidateToken()


    /**
     * Evicts a key from every layer.
     *
     * @param string $key The key from buildKey().
     *
     * @return void
     */
    public function forget(string $key): void
    {
        unset($this->memory[$key]);

        if ($this->apcuEnabled === true) {
            apcu_delete(self::APCU_PREFIX . $key);
        }

        if ($this->crypto === null) {
            return;
        }

        try {
            $this->deleteStored($key);
        } catch (Exception $exception) {
            $this->logFailure("Unable to delete a cached installation token: {error}", $exception);
        }

    }//end forget()


    /**
     * Whether a token expiring at the given time can still be served.
     *
     * @param int $expiresAt The Unix time GitHub said the token expires at.
     *
     * @return bool True while outside the safety margin.
     */
    private function isUsable(int $expiresAt): bool
    {
        return ($expiresAt - self::SAFETY_MARGIN_SECONDS) > ($this->clock)();

    }//end isUsable()


    /**
     * Reads the process layer.
     *
     * @param string $key The cache key.
     *
     * @return array{token: string, expiresAt: int}|null Null when missing or expired.
     */
    private function fromMemory(string $key): ?array
    {
        $entry = ($this->memory[$key] ?? null);
        if ($entry === null) {
            return null;
        }

        if ($this->isUsable($entry["expiresAt"]) === false) {
            unset($this->memory[$key], $this->keysByToken[$entry["token"]]);
            return null;
        }

        return $entry;

    }//end fromMemory()


    /**
     * Reads the APCu layer.
     *
     * @param string $key The cache key.
     *
     * @return array{token: string, expiresAt: int}|null Null when disabled, missing or expired.
     */
    private function fromApcu(string $key): ?array
    {
        if ($this->apcuEnabled === false) {
            return null;
        }

        $success = false;
        $entry = apcu_fetch(self::APCU_PREFIX . $key, $success);
        if ($success === false || is_array($entry) === false || isset($entry["token"], $entry["expiresAt"]) === false) {
            return null;
        }

        if ($this->isUsable((int) $entry["expiresAt"]) === false) {
            return null;
        }

        return ["token" => (string) $entry["token"], "expiresAt" => (int) $entry["expiresAt"]];

    }//end fromApcu()


    /**
     * Writes to the APCu layer, expiring with the token's safety margin.
     *
     * @param string                                $key   The cache key.
     * @param array{token: string, expiresAt: int} $entry The token.
     *
     * @return void
     */
    private function toApcu(string $key, array $entry): void
    {
        if ($this->apcuEnabled === false) {
            return;
        }

        $ttl = ($entry["expiresAt"] - self::SAFETY_MARGIN_SECONDS - ($this->clock)());
        if ($ttl > 0) {
            apcu_store(self::APCU_PREFIX . $key, $entry, $ttl);
        }

    }//end toApcu()


    /**
     * Reads and decrypts the database layer, filling APCu on a hit.
     *
     * @param string $key The cache key.
     *
     * @return array{token: string, expiresAt: int}|null Null when disabled, missing, expired or unreadable.
     */
    private function fromDatabase(string $key): ?array
    {
        if ($this->crypto === null) {
            return null;
        }

        try {
            $row = $this->readStored($key);
        } catch (Exception $exception) {
            $this->logFailure("Unable to read a cached installation token: {error}", $exception);
            return null;
        }

        if ($row === null || $this->isUsable((int) $row->expiresAt) === false) {
            return null;
        }

        try {
            $token = $this->crypto->decrypt((string) $row->encryptedToken);
        } catch (RuntimeException $exception) {
            // Encrypted with another key (rotated) or corrupted: mint a new one, which overwrites it.
            $this->logFailure("Unable to decrypt a cached installation token: {error}", $exception);
            return null;
        }

        $entry = ["token" => $token, "expiresAt" => (int) $row->expiresAt];
        $this->toApcu($key, $entry);

        return $entry;

    }//end fromDatabase()


    /**
     * Encrypts and writes to the database layer.
     *
     * @param string                                $key            The cache key.
     * @param int                                   $installationId The installation.
     * @param array{token: string, expiresAt: int} $entry          The token.
     *
     * @return void
     */
    private function toDatabase(string $key, int $installationId, array $entry): void
    {
        if ($this->crypto === null) {
            return;
        }

        try {
            $this->writeStored($key, $installationId, $this->crypto->encrypt($entry["token"]), $entry["expiresAt"]);
        } catch (Exception $exception) {
            $this->logFailure("Unable to store an installation token: {error}", $exception);
        }

    }//end toDatabase()


    /**
     * Checks a minted token has the shape getOrMint() caches.
     *
     * @param mixed $entry What the mint callable returned.
     *
     * @return void
     *
     * @throws RuntimeException When the token or its expiry is missing.
     */
    private function validateMinted(mixed $entry): void
    {
        if (is_array($entry) === false
            || is_string($entry["token"] ?? null) === false
            || $entry["token"] === ""
            || is_int($entry["expiresAt"] ?? null) === false
        ) {
            throw new RuntimeException("The minted installation token must be an array{token: string, expiresAt: int}.");
        }

    }//end validateMinted()


    /**
     * Logs a layer failure; the caller goes on with the next layer.
     *
     * @param string    $message   The message, with an {error} placeholder.
     * @param Exception $exception The failure.
     *
     * @return void
     */
    private function logFailure(string $message, Exception $exception): void
    {
        global $logStream;

        $context = [];
        $context["error"] = $exception->getMessage();
        $logStream?->warning($message, $context, self::LOG_CATEGORY);

    }//end logFailure()


    /**
     * Reads the stored row of a key.
     *
     * @param string $key The cache key.
     *
     * @return stdClass|null The row (encryptedToken, expiresAt), or null when none is stored.
     */
    protected function readStored(string $key): ?stdClass
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("SELECT EncryptedToken AS encryptedToken, ExpiresAt AS expiresAt FROM github_installation_tokens WHERE CacheKey = ?");
        $stmt->bind_param("s", $key);
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

    }//end readStored()


    /**
     * Upserts the stored row of a key, and clears the rows already expired.
     *
     * @param string $key            The cache key.
     * @param int    $installationId The installation.
     * @param string $encryptedToken The token, encrypted.
     * @param int    $expiresAt      The Unix time the token expires at.
     *
     * @return void
     */
    protected function writeStored(string $key, int $installationId, string $encryptedToken, int $expiresAt): void
    {
        $mysqli = connectToDatabase();

        $sql = "INSERT INTO github_installation_tokens (CacheKey, InstallationId, EncryptedToken, ExpiresAt)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE EncryptedToken = VALUES(EncryptedToken), ExpiresAt = VALUES(ExpiresAt), UpdatedAt = CURRENT_TIMESTAMP";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sisi", $key, $installationId, $encryptedToken, $expiresAt);
        if ($stmt->execute() === false) {
            $failure = statementFailed($stmt);
            $stmt->close();
            $mysqli->close();
            throw $failure;
        }

        $stmt->close();

        // Housekeeping: a row is only ever rewritten by its own key, so expired ones would pile up.
        $now = ($this->clock)();
        $cleanup = $mysqli->prepare("DELETE FROM github_installation_tokens WHERE ExpiresAt < ?");
        $cleanup->bind_param("i", $now);
        $cleanup->execute();
        $cleanup->close();
        $mysqli->close();

    }//end writeStored()


    /**
     * Deletes the stored row of a key.
     *
     * @param string $key The cache key.
     *
     * @return void
     */
    protected function deleteStored(string $key): void
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("DELETE FROM github_installation_tokens WHERE CacheKey = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $stmt->close();
        $mysqli->close();

    }//end deleteStored()


    /**
     * Whether the APCu extension is loaded and enabled for this SAPI.
     *
     * @return bool True when APCu can be used.
     */
    private static function isApcuAvailable(): bool
    {
        return function_exists("apcu_enabled") && apcu_enabled();

    }//end isApcuAvailable()


    /**
     * Builds the CryptoHelper from the configured key.
     *
     * @return CryptoHelper|null Null when encryption isn't configured.
     */
    private static function buildCrypto(): ?CryptoHelper
    {
        global $logStream;

        try {
            return new CryptoHelper();
        } catch (RuntimeException $exception) {
            $context = [];
            $context["error"] = $exception->getMessage();
            $logStream?->warning("Installation tokens are not stored in the database: {error}", $context, self::LOG_CATEGORY);
        }

        return null;

    }//end buildCrypto()


}//end class
