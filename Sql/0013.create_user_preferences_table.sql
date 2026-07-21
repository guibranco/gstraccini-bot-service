CREATE TABLE
    `user_preferences` (
        `UserId` BIGINT UNSIGNED NOT NULL,
        `Theme` ENUM('light', 'dark', 'system') NOT NULL DEFAULT 'system',
        `GroupByOrganization` TINYINT(1) NOT NULL DEFAULT 1,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`UserId`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
