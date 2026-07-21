CREATE TABLE
    `user_integrations` (
        `UserId` BIGINT UNSIGNED NOT NULL,
        `Provider` VARCHAR(100) NOT NULL,
        `EncryptedApiKey` VARCHAR(1000) NOT NULL,
        `Status` VARCHAR(50) NOT NULL DEFAULT 'Validated',
        `LastUsedAt` TIMESTAMP NULL DEFAULT NULL,
        `LastError` VARCHAR(500) NULL DEFAULT NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`UserId`, `Provider`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
