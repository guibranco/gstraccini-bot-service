-- Mirror of webhooks Sql/0047 (see webhooks docs/adr/0002): the live table is created there.

-- Pending confirmations of GStraccini-bot.
--
-- Some of the bot's pull request comments wait for the user to tick a checkbox before the bot carries on:
-- the version bump decision ("GStraccini Checks: Pull Request" stays in progress until one option is
-- picked) and the `.infisicalignore` suggestion approval. Until now the only record of them was the
-- hidden marker in the comment itself, so nothing could list what is still waiting on someone.
--
-- gstraccini-bot-service writes one row per such comment when it posts it (or finds one posted before this
-- table existed), and moves it out of `pending` when:
--
-- * the user ticks a box and the bot applies it                    → Status `resolved`, Resolution = the choice
-- * the decision is no longer needed (a `+semver` commit was pushed
--   by hand, the PR no longer looks like a feature)                 → Status `resolved`, Resolution `superseded`
-- * the pull request is merged or closed first, or the comment is
--   deleted                                                         → Status `cancelled`, Resolution `merged` /
--                                                                     `closed` / `comment_deleted`
--
-- A scheduled job in the bot's comments worker pings the pull request author about rows still pending after
-- a few days (ReminderCount / LastReminderAt), and the bot's API lists them for the portal
-- (GET /v1/pending-confirmations/).
--
-- * Kind: `version_bump` | `infisical_ignore` (more to come).
-- * CommentId: the bot's own comment holding the checkboxes; one row per comment.

CREATE TABLE IF NOT EXISTS
    `pending_confirmations` (
        `Sequence` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `Kind` VARCHAR(50) NOT NULL,
        `RepositoryOwner` VARCHAR(250) NOT NULL,
        `RepositoryName` VARCHAR(250) NOT NULL,
        `InstallationId` BIGINT UNSIGNED NOT NULL,
        `PullRequestNumber` BIGINT UNSIGNED NOT NULL,
        `PullRequestTitle` VARCHAR(255) DEFAULT NULL,
        `PullRequestAuthor` VARCHAR(250) DEFAULT NULL,
        `CommentId` BIGINT UNSIGNED NOT NULL,
        `Url` VARCHAR(500) DEFAULT NULL,
        `Status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `Resolution` VARCHAR(50) DEFAULT NULL,
        `ResolvedAt` TIMESTAMP NULL DEFAULT NULL,
        `ReminderCount` INT UNSIGNED NOT NULL DEFAULT 0,
        `LastReminderAt` TIMESTAMP NULL DEFAULT NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`Sequence`),
        UNIQUE KEY `uq_pending_confirmations_comment_id` (`CommentId`),
        KEY `idx_pending_confirmations_pull_request` (`RepositoryOwner`, `RepositoryName`, `PullRequestNumber`),
        KEY `idx_pending_confirmations_status` (`Status`, `CreatedAt`),
        KEY `idx_pending_confirmations_installation_id` (`InstallationId`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
