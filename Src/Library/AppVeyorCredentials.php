<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * An AppVeyor API key and the account it works with.
 *
 * AppVeyor has two kinds of API keys. An account-level key (no prefix) is
 * bound to one account, so calls go straight to `/api/...`. A user-level key
 * (prefixed `v2.`) works with any account the user can access, so calls must
 * name the account: `/api/account/<account-name>/...`.
 */
final class AppVeyorCredentials
{
    /**
     * The prefix of a user-level API key.
     */
    public const USER_KEY_PREFIX = "v2.";

    /**
     * The AppVeyor REST API base URL.
     */
    public const API_URL = "https://ci.appveyor.com/api/";


    /**
     * Holds the credentials.
     *
     * @param string $key         The AppVeyor API key.
     * @param string $accountName The AppVeyor account a user-level key works with.
     *
     * @return void
     */
    public function __construct(
        public readonly string $key,
        public readonly string $accountName
    ) {

    }//end __construct()


    /**
     * Whether the key is a user-level (v2) key, which needs the account in the URL.
     *
     * @return boolean True for a key starting with `v2.`.
     */
    public function isUserLevelKey(): bool
    {
        return str_starts_with($this->key, self::USER_KEY_PREFIX);

    }//end isUserLevelKey()


    /**
     * The base URL API endpoints are appended to.
     *
     * @return string `/api/` for an account-level key, `/api/account/<account-name>/` for a user-level key.
     */
    public function apiBaseUrl(): string
    {
        $baseUrl = self::API_URL;
        if ($this->isUserLevelKey() === false) {
            return $baseUrl;
        }

        $account = rawurlencode($this->accountName);
        return "{$baseUrl}account/{$account}/";

    }//end apiBaseUrl()


}//end class
