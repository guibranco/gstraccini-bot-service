<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * A GitHub App installation access token, the installation it belongs to and the
 * time GitHub said it expires at.
 */
final class InstallationToken
{
    /**
     * Seconds before GitHub's expiry at which the token stops being served, so an
     * operation never starts with a token about to lapse.
     */
    public const SAFETY_MARGIN_SECONDS = 300;


    /**
     * Holds the token.
     *
     * @param string $token          The installation access token.
     * @param int    $installationId The installation the token belongs to.
     * @param int    $expiresAt      The Unix time GitHub said the token expires at.
     *
     * @return void
     */
    public function __construct(
        public readonly string $token,
        public readonly int $installationId,
        public readonly int $expiresAt
    ) {

    }//end __construct()


    /**
     * Seconds the token can still be served for, from a given time.
     *
     * @param int $now The current Unix time.
     *
     * @return int The seconds left before the safety margin; 0 or less once it is reached.
     */
    public function getUsableSeconds(int $now): int
    {
        $seconds = ($this->expiresAt - self::SAFETY_MARGIN_SECONDS - $now);
        return $seconds;

    }//end getUsableSeconds()


    /**
     * Whether the token can still be served at a given time.
     *
     * @param int $now The current Unix time.
     *
     * @return bool True while outside the safety margin.
     */
    public function isUsableAt(int $now): bool
    {
        return $this->getUsableSeconds($now) > 0;

    }//end isUsableAt()


}//end class
