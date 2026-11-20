<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * A decrypted API key read from a user's integration in `user_integrations`.
 */
final class IntegrationKey
{


    /**
     * Holds the key.
     *
     * @param string $key    The decrypted API key.
     * @param string $login  The GitHub login of the user who stored it.
     * @param int    $userId The user who stored it.
     *
     * @return void
     */
    public function __construct(
        public readonly string $key,
        public readonly string $login,
        public readonly int $userId
    ) {

    }//end __construct()


}//end class
