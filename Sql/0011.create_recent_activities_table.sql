DROP TABLE IF EXISTS `recent_activities`;

CREATE TABLE
    `recent_activities` (
        `Sequence` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `RepositoryOwner` VARCHAR(250) NOT NULL,
        `RepositoryName` VARCHAR(250) NOT NULL,
        `InstallationId` BIGINT UNSIGNED NULL,
        `ActionType` VARCHAR(50) NOT NULL,
        `Title` VARCHAR(255) NOT NULL,
        `Url` VARCHAR(500) NULL,
        `PullRequestId` BIGINT UNSIGNED NULL,
        `PullRequestNumber` BIGINT UNSIGNED NULL,
        `PullRequestNodeId` VARCHAR(250) NULL,
        `IssueId` BIGINT UNSIGNED NULL,
        `IssueNumber` BIGINT UNSIGNED NULL,
        `IssueNodeId` VARCHAR(250) NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`Sequence`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE INDEX idx_recent_activities_installation_id ON recent_activities (`InstallationId`);
CREATE INDEX idx_recent_activities_created_at ON recent_activities (`CreatedAt`);
