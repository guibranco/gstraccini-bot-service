<?php

namespace GuiBranco\GStracciniBot\Library;

use RuntimeException;

/**
 * GitHub refused the commit bumping the major version of a breaking change pull request.
 */
class BreakingChangeCommitException extends RuntimeException
{
}//end class
