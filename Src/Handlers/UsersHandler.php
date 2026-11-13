<?php

namespace GuiBranco\GStracciniBot\Handlers;

use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;

/**
 * Handles user events shared by the HTTP webhook entry point
 * (Src/users.php) and the queue worker (Src/Workers/users.php).
 *
 * Maps every installation the user has access to (via GitHub's
 * `GET /user/installations`) into the `user_installations` table, so
 * dashboard queries can scope data to the installations a given user
 * is actually allowed to see.
 */
class UsersHandler implements IHandler
{
    private GitHubCollectionFetcher $fetcher;

    public function __construct(?GitHubCollectionFetcher $fetcher = null)
    {
        $this->fetcher = $fetcher ?? new GitHubCollectionFetcher(0);
    }

    public function handleItem($user): void
    {
        global $logStream;

        $userId = (int) $user->UserId;

        $logStream?->info(
            'Processing user event for {user}',
            ['user' => $user->Login, 'userId' => $userId],
            "users"
        );

        if (empty($user->Token)) {
            $logStream?->warning(
                'No token available for user {user} — skipping installation mapping',
                ['user' => $user->Login, 'userId' => $userId],
                "users"
            );
            return;
        }

        $installations = $this->fetcher->fetchAllPages($user->Token, "user/installations", "installations");

        $activeInstallationIds = [];
        foreach ($installations as $installation) {
            $installationId = (int) $installation->id;
            $activeInstallationIds[] = $installationId;
            upsertUserInstallation($userId, $installationId);
            $logStream?->debug('Mapped installation {login} (#{installationId}) to user {user}', ['user' => $user->Login, 'userId' => $userId, 'login' => $installation->account->login, 'installationId' => $installationId], "users");
        }

        $removed = removeStaleUserInstallations($userId, $activeInstallationIds);
        if ($removed > 0) {
            $logStream?->notice('Removed {removed} stale installation mapping(s) of user {user}', ['user' => $user->Login, 'userId' => $userId, 'removed' => $removed], "users");
        }
    }
}
