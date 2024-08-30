DROP TABLE IF EXISTS `github_signature`;

CREATE TABLE
    `github_signature` (
        `Sequence` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `Date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `DeliveryId` BINARY(16) NOT NULL,
        `DeliveryIdText` VARCHAR(36) GENERATED ALWAYS AS (
            INSERT(INSERT(INSERT(INSERT(hex(`DeliveryId`),9,0,'-'),14,0,'-'),19,0,'-'),24,0,'-')
        ) VIRTUAL,
        `HookId` BIGINT UNSIGNED NOT NULL,
        `TargetId` BIGINT UNSIGNED NOT NULL,
        `TargetType` VARCHAR(255) NOT NULL,        
        `RepositoryOwner` VARCHAR(250) NOT NULL,
        `RepositoryName` VARCHAR(250) NOT NULL,
        `Event` VARCHAR(250) NOT NULL,
        `ExpectedHash` VARCHAR(250) NULL,
        `ActualHash` VARCHAR(250) NULL,
        `InstallationId` BIGINT UNSIGNED NULL,
        `Error` VARCHAR(250) NOT NULL,
        `ResponseHeaders` TEXT NULL,
        `ResponsePayload` TEXT NULL,
        `Processed` BOOLEAN NOT NULL DEFAULT FALSE,
        `ProcessedDate` TIMESTAMP NULL,
        PRIMARY KEY (`Sequence`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
