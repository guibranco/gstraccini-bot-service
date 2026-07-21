CREATE TABLE
    `user_credentials` (
        `UserId` BIGINT UNSIGNED NOT NULL,
        `PasswordHash` VARCHAR(255) NOT NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`UserId`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE
    `user_totp` (
        `UserId` BIGINT UNSIGNED NOT NULL,
        `EncryptedSecret` VARCHAR(500) NOT NULL,
        `Enabled` TINYINT(1) NOT NULL DEFAULT 0,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`UserId`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE
    `user_recovery_codes` (
        `Sequence` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `UserId` BIGINT UNSIGNED NOT NULL,
        `CodeHash` VARCHAR(255) NOT NULL,
        `UsedAt` TIMESTAMP NULL DEFAULT NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`Sequence`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE INDEX idx_user_recovery_codes_user_id ON user_recovery_codes (`UserId`);

CREATE TABLE
    `user_password_resets` (
        `Sequence` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `UserId` BIGINT UNSIGNED NOT NULL,
        `TokenHash` VARCHAR(255) NOT NULL,
        `ExpiresAt` TIMESTAMP NOT NULL,
        `UsedAt` TIMESTAMP NULL DEFAULT NULL,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`Sequence`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE INDEX idx_user_password_resets_user_id ON user_password_resets (`UserId`);
