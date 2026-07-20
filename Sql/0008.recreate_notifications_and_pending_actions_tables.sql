DROP TABLE IF EXISTS `pending_actions`;
DROP TABLE IF EXISTS `notifications`;

CREATE TABLE
    `notifications` (
        `Sequence` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `RepositoryOwner` VARCHAR(250) NOT NULL,
        `RepositoryName` VARCHAR(250) NOT NULL,
        `Type` VARCHAR(50) NOT NULL,
        `Title` VARCHAR(255) NOT NULL,
        `Message` TEXT NOT NULL,
        `Url` VARCHAR(500) NULL,
        `PullRequestId` BIGINT UNSIGNED NULL,
        `PullRequestNumber` BIGINT UNSIGNED NULL,
        `PullRequestNodeId` VARCHAR(250) NULL,
        `IsRead` BOOLEAN NOT NULL DEFAULT FALSE,
        `ReadAt` TIMESTAMP NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`Sequence`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE INDEX idx_notifications_is_read ON notifications (`IsRead`);
CREATE INDEX idx_notifications_pull_request_node_id ON notifications (`PullRequestNodeId`);

CREATE TABLE
    `pending_actions` (
        `Sequence` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `NotificationSequence` BIGINT UNSIGNED NOT NULL,
        `RepositoryOwner` VARCHAR(250) NOT NULL,
        `RepositoryName` VARCHAR(250) NOT NULL,
        `ActionType` VARCHAR(50) NOT NULL,
        `Title` VARCHAR(255) NOT NULL,
        `Description` TEXT NULL,
        `Url` VARCHAR(500) NULL,
        `PullRequestId` BIGINT UNSIGNED NULL,
        `PullRequestNumber` BIGINT UNSIGNED NULL,
        `PullRequestNodeId` VARCHAR(250) NULL,
        `IsRead` BOOLEAN NOT NULL DEFAULT FALSE,
        `ReadAt` TIMESTAMP NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`Sequence`),
        CONSTRAINT `fk_pending_actions_notification`
            FOREIGN KEY (`NotificationSequence`) REFERENCES `notifications` (`Sequence`) ON DELETE CASCADE
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE INDEX idx_pending_actions_is_read ON pending_actions (`IsRead`);
CREATE INDEX idx_pending_actions_pull_request_node_id ON pending_actions (`PullRequestNodeId`);
