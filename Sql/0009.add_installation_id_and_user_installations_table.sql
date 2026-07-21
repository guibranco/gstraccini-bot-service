ALTER TABLE `notifications`
    ADD COLUMN `InstallationId` BIGINT UNSIGNED NULL AFTER `RepositoryName`;

ALTER TABLE `pending_actions`
    ADD COLUMN `InstallationId` BIGINT UNSIGNED NULL AFTER `RepositoryName`;

CREATE INDEX idx_notifications_installation_id ON notifications (`InstallationId`);
CREATE INDEX idx_pending_actions_installation_id ON pending_actions (`InstallationId`);

CREATE TABLE
    `user_installations` (
        `UserId` BIGINT UNSIGNED NOT NULL,
        `InstallationId` BIGINT UNSIGNED NOT NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`UserId`, `InstallationId`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE INDEX idx_user_installations_installation_id ON user_installations (`InstallationId`);
