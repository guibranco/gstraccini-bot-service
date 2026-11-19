<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * The GitHub token of a real user who owns, or has access to, an
 * installation. The bot uses it for the few actions that must come from a
 * person rather than from the app: Dependabot and Depfu commands
 * (`@dependabot recreate`, `@depfu merge`) and the owner's approval of a
 * trusted submitter's pull request.
 */
final class OwnerCredentials
{
    /** The token was read from the user's `GitHub` integration in `user_integrations`. */
    public const SOURCE_DATABASE = "database";

    /** The token is the service-wide `$gitHubUserToken` secret, used while no integration is stored. */
    public const SOURCE_FALLBACK = "fallback";

    public function __construct(
        public readonly string $token,
        public readonly string $login,
        public readonly string $source
    ) {
    }
}
