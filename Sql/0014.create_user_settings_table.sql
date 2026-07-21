CREATE TABLE
    `user_settings` (
        `UserId` BIGINT UNSIGNED NOT NULL,
        `CreateLabels` TINYINT(1) NOT NULL DEFAULT 1,
        `NotifyIssues` TINYINT(1) NOT NULL DEFAULT 1,
        `RequireAcceptanceCriteriaChecklist` TINYINT(1) NOT NULL DEFAULT 1,
        `ReminderIssues` TINYINT(1) NOT NULL DEFAULT 1,
        `ReminderIssuesDays` SMALLINT UNSIGNED NULL DEFAULT 10,
        `PrTemplateDescription` TINYINT(1) NOT NULL DEFAULT 1,
        `AutoReviewPr` TINYINT(1) NOT NULL DEFAULT 1,
        `AutoMergePr` TINYINT(1) NOT NULL DEFAULT 1,
        `CreateIssue` TINYINT(1) NOT NULL DEFAULT 1,
        `NotifyPullRequests` TINYINT(1) NOT NULL DEFAULT 1,
        `CreatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `UpdatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`UserId`)
    ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE utf8mb4_unicode_ci;
