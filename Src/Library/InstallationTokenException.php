<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Thrown when an installation access token can't be minted: GitHub answered
 * without a usable token, or the mint returned something else.
 */
class InstallationTokenException extends \RuntimeException
{
}//end class
