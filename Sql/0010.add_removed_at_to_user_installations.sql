ALTER TABLE `user_installations`
    ADD COLUMN `RemovedAt` TIMESTAMP NULL DEFAULT NULL AFTER `CreatedAt`;

CREATE INDEX idx_user_installations_removed_at ON user_installations (`RemovedAt`);
