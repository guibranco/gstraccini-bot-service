<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Keeps installation access tokens in APCu, shared by the requests one PHP pool
 * serves. Only used when the extension is loaded and enabled.
 */
class ApcuTokenStore
{
    /**
     * Prefix of the keys, so they can't clash with other applications of the pool.
     */
    private const PREFIX = "gstraccini:installation-token:";


    /**
     * Whether APCu is loaded and enabled for this SAPI.
     *
     * @return bool True when the store can be used.
     */
    public function isAvailable(): bool
    {
        return function_exists("apcu_enabled") === true && apcu_enabled() === true;

    }//end isAvailable()


    /**
     * Reads the token of a key.
     *
     * @param string $key The cache key.
     *
     * @return InstallationToken|null Null when none is stored.
     */
    public function read(string $key): ?InstallationToken
    {
        $success = false;
        $stored = apcu_fetch($this->buildApcuKey($key), $success);
        if ($success === true && $stored instanceof InstallationToken) {
            return $stored;
        }

        return null;

    }//end read()


    /**
     * Stores the token of a key until its safety margin.
     *
     * @param string            $key   The cache key.
     * @param InstallationToken $token The token.
     * @param int               $now   The current Unix time.
     *
     * @return void
     */
    public function write(string $key, InstallationToken $token, int $now): void
    {
        $ttl = $token->getUsableSeconds($now);
        if ($ttl > 0) {
            apcu_store($this->buildApcuKey($key), $token, $ttl);
        }

    }//end write()


    /**
     * Deletes the token of a key.
     *
     * @param string $key The cache key.
     *
     * @return void
     */
    public function delete(string $key): void
    {
        apcu_delete($this->buildApcuKey($key));

    }//end delete()


    /**
     * The APCu key of a cache key.
     *
     * @param string $key The cache key.
     *
     * @return string The prefixed key.
     */
    private function buildApcuKey(string $key): string
    {
        return sprintf("%s%s", self::PREFIX, $key);

    }//end buildApcuKey()


}//end class
