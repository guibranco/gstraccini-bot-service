<?php

namespace GuiBranco\GStracciniBot\Library;

use Exception;

/**
 * Reuses GitHub App installation access tokens until they expire, instead of
 * minting a new one (`POST /app/installations/{id}/access_tokens`) per operation.
 *
 * A token is served from the current process first, then from each store in
 * order (APCu, then the database), and only minted when none has a token
 * outside its safety margin. A token found in a store is copied to the stores
 * before it; a minted one is written to all of them.
 *
 * Every store is best effort: a failure is logged and the next store, and
 * eventually GitHub, is used, so caching never stops an item.
 */
class InstallationTokenCache
{
    /**
     * The log category.
     */
    private const LOG_CATEGORY = "installation_token";

    /**
     * Tokens held by this process, keyed by cache key.
     *
     * @var array<string, InstallationToken>
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
     * The shared stores, fastest first.
     *
     * @var array<int, ApcuTokenStore|DatabaseTokenStore>
     */
    private array $stores;

    /**
     * Returns the current Unix time.
     *
     * @var callable
     */
    private $clock;


    /**
     * Sets up the cache.
     *
     * @param array<int, ApcuTokenStore|DatabaseTokenStore> $stores The shared stores, fastest first.
     * @param callable                                      $clock  Returns the current Unix time.
     *
     * @return void
     */
    public function __construct(array $stores, callable $clock)
    {
        $this->stores = array_values($stores);
        $this->clock = $clock;

    }//end __construct()


    /**
     * The cache key of a token request: the installation and the permissions asked.
     * Tokens cover every repository of the installation, so one token per
     * installation (and permission set) is shared by all its repositories.
     *
     * @param string     $installationId The installation.
     * @param array|null $permissions    The permissions asked, or null for the app's.
     *
     * @return string A 64-character hex key.
     */
    public function buildKey(string $installationId, ?array $permissions): string
    {
        $sorted = ($permissions ?? []);
        ksort($sorted);
        $encoded = json_encode($sorted);

        return hash("sha256", "{$installationId}|{$encoded}");

    }//end buildKey()


    /**
     * Returns a cached, still-usable token for the key, or mints one.
     *
     * @param string   $key  The key from buildKey().
     * @param callable $mint Mints a token; returns an InstallationToken.
     *
     * @return string The token.
     *
     * @throws InstallationTokenException When the mint callable doesn't return an InstallationToken.
     */
    public function getOrMint(string $key, callable $mint): string
    {
        $token = $this->fromMemory($key);
        if ($token === null) {
            $token = $this->fromStores($key);
        }

        if ($token === null) {
            $token = $mint();
            if (($token instanceof InstallationToken) === false) {
                throw new InstallationTokenException("The mint callable must return an InstallationToken.");
            }

            $this->toStores($key, $token, count($this->stores));
        }

        $this->memory[$key] = $token;
        $this->keysByToken[$token->token] = $key;

        return $token->token;

    }//end getOrMint()


    /**
     * Evicts a token GitHub rejected (revoked early: app uninstalled, permissions
     * changed), so the next operation mints a new one. Tokens this process didn't
     * hand out are ignored.
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
     * Evicts a key from the process and every store.
     *
     * @param string $key The key from buildKey().
     *
     * @return void
     */
    public function forget(string $key): void
    {
        unset($this->memory[$key]);

        foreach ($this->stores as $store) {
            try {
                $store->delete($key);
            } catch (Exception $exception) {
                $this->logFailure("Unable to delete a cached installation token: {error}", $exception);
            }
        }

    }//end forget()


    /**
     * Reads the process layer.
     *
     * @param string $key The cache key.
     *
     * @return InstallationToken|null Null when missing or within its safety margin.
     */
    private function fromMemory(string $key): ?InstallationToken
    {
        $token = ($this->memory[$key] ?? null);
        if ($token === null) {
            return null;
        }

        if ($token->isUsableAt(($this->clock)()) === false) {
            unset($this->memory[$key], $this->keysByToken[$token->token]);
            return null;
        }

        return $token;

    }//end fromMemory()


    /**
     * Reads the stores in order, copying a token found to the stores before it.
     *
     * @param string $key The cache key.
     *
     * @return InstallationToken|null Null when no store has a usable token.
     */
    private function fromStores(string $key): ?InstallationToken
    {
        $now = ($this->clock)();
        foreach ($this->stores as $index => $store) {
            $token = $this->readStore($store, $key);
            if ($token !== null && $token->isUsableAt($now) === true) {
                $this->toStores($key, $token, $index);
                return $token;
            }
        }

        return null;

    }//end fromStores()


    /**
     * Reads one store, logging a failure instead of throwing it.
     *
     * @param ApcuTokenStore|DatabaseTokenStore $store The store.
     * @param string                            $key   The cache key.
     *
     * @return InstallationToken|null Null when missing or unreadable.
     */
    private function readStore(ApcuTokenStore|DatabaseTokenStore $store, string $key): ?InstallationToken
    {
        try {
            return $store->read($key);
        } catch (Exception $exception) {
            $this->logFailure("Unable to read a cached installation token: {error}", $exception);
        }

        return null;

    }//end readStore()


    /**
     * Writes a token to the first stores, logging failures instead of throwing them.
     *
     * @param string            $key   The cache key.
     * @param InstallationToken $token The token.
     * @param int               $count How many stores, from the first, to write to.
     *
     * @return void
     */
    private function toStores(string $key, InstallationToken $token, int $count): void
    {
        $now = ($this->clock)();
        foreach (array_slice($this->stores, 0, $count) as $store) {
            try {
                $store->write($key, $token, $now);
            } catch (Exception $exception) {
                $this->logFailure("Unable to store an installation token: {error}", $exception);
            }
        }

    }//end toStores()


    /**
     * Logs a store failure; the caller goes on without that store.
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


}//end class
