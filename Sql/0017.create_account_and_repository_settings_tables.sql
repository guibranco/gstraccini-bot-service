-- Account-level and repository-level settings for GStraccini-bot (opt-in features).
--
-- The first setting is RequestVersionBump: whether the bot asks, on a pull request that looks like a
-- feature and carries no GitVersion `+semver:` directive, for a version bump decision (the "Version bump
-- needed" comment with the major / minor / none checkboxes, holding the "pull request" check run as
-- action_required until one is checked). It used to run on every repository; it is now opt-in.
--
-- 1. account_settings: one row per GitHub account (user or organization) the app is installed on, keyed by
--    the account's GitHub id (github_installations.AccountId), not by InstallationId, so uninstalling and
--    reinstalling the app keeps the settings. AccountLogin is informational (logins can be renamed).
--    RequestVersionBump: 1 = enabled for every repository of the account, 0 = disabled (default).
-- 2. repository_settings: one row per repository, keyed by its GitHub id (github_repositories.Id).
--    RequestVersionBump: NULL = inherit the account setting (default), 1 = enabled, 0 = disabled, so a
--    repository can opt in while its account is off, or opt out while its account is on.
--
-- Effective value for a pull request = repository_settings value when not NULL, else the account_settings
-- value, else 0. No row means "default", so nothing is enabled until someone opts in.
--
-- UpdatedByUserId is the GitHub user id of whoever last changed the row through the website / portal
-- (the same id as user_settings.UserId). Read and written only by gstraccini-bot-service.
--
-- The live copy of this migration is webhooks Sql/0048. This one belongs to the dormant history kept for
-- the future dedicated database and must not be run against the shared webhooks database.

CREATE TABLE IF NOT EXISTS
    `account_settings` (
        `AccountId` BIGINT UNSIGNED NOT NULL,
        `AccountLogin` VARCHAR(255) NOT NULL,
        `RequestVersionBump` TINYINT(1) NOT NULL DEFAULT 0,
        `UpdatedByUserId` BIGINT UNSIGNED NULL DEFAULT NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`AccountId`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS
    `repository_settings` (
        `RepositoryId` BIGINT UNSIGNED NOT NULL,
        `AccountId` BIGINT UNSIGNED NOT NULL,
        `FullName` VARCHAR(250) NOT NULL,
        `RequestVersionBump` TINYINT(1) NULL DEFAULT NULL,
        `UpdatedByUserId` BIGINT UNSIGNED NULL DEFAULT NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`RepositoryId`),
        KEY `idx_repository_settings_account_id` (`AccountId`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
