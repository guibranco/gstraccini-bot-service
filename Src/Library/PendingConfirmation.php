<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * A bot comment waiting for the user to tick a checkbox (a row of the
 * `pending_confirmations` table, created by a migration in the webhooks repository).
 */
final class PendingConfirmation
{
    /**
     * The version bump decision (VersionBumpCommentBuilder).
     */
    public const KIND_VERSION_BUMP = 'version_bump';

    /**
     * The `.infisicalignore` suggestion approval (InfisicalIgnoreApprovalCommentBuilder).
     */
    public const KIND_INFISICAL_IGNORE = 'infisical_ignore';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_RESOLVED  = 'resolved';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Resolved without the user's choice: no longer needed (e.g. a `+semver` commit pushed by hand).
     */
    public const RESOLUTION_SUPERSEDED = 'superseded';

    /**
     * Resolved: the choice was applied, the comment carries the completion marker.
     */
    public const RESOLUTION_APPLIED = 'applied';

    public const RESOLUTION_MERGED          = 'merged';
    public const RESOLUTION_CLOSED          = 'closed';
    public const RESOLUTION_COMMENT_DELETED = 'comment_deleted';


    /**
     * @param integer     $sequence          The row id (0 before it is stored).
     * @param string      $kind              One of the KIND_* constants.
     * @param string      $owner             Repository owner.
     * @param string      $repo              Repository name.
     * @param integer     $installationId    The GitHub App installation.
     * @param integer     $pullRequestNumber The pull request.
     * @param integer     $commentId         The bot's comment holding the checkboxes.
     * @param string|null $url               Link to the comment.
     * @param string|null $title             The pull request title.
     * @param string|null $author            The pull request author's login.
     * @param integer     $reminderCount     How many reminders were posted.
     */
    public function __construct(
        public readonly int $sequence,
        public readonly string $kind,
        public readonly string $owner,
        public readonly string $repo,
        public readonly int $installationId,
        public readonly int $pullRequestNumber,
        public readonly int $commentId,
        public readonly ?string $url=null,
        public readonly ?string $title=null,
        public readonly ?string $author=null,
        public readonly int $reminderCount=0
    ) {

    }//end __construct()


    /**
     * What the user is asked to confirm, for messages.
     *
     * @return string
     */
    public function describe(): string
    {
        return match ($this->kind) {
            self::KIND_VERSION_BUMP => 'a version bump choice',
            self::KIND_INFISICAL_IGNORE => 'an approval to apply the `.infisicalignore` suggestion',
            default => 'a confirmation',
        };

    }//end describe()


}//end class
