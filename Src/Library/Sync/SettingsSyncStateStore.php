<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

use RuntimeException;

/**
 * Sync state kept in the shared database's `settings` table (key/value,
 * unique on `name`), which the webhooks project created for its own
 * runtime settings. Keys written by the bot are prefixed with
 * `gstraccini-bot.` so they can't collide with that project's settings and
 * are easy to spot.
 */
class SettingsSyncStateStore implements SyncStateStoreInterface
{
    public const KEY_PREFIX = "gstraccini-bot.";

    public function get(string $key): ?string
    {
        $name = self::KEY_PREFIX . $key;

        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("SELECT `value` FROM `settings` WHERE `name` = ? LIMIT 1");
        $stmt->bind_param("s", $name);

        try {
            if (!$stmt->execute()) {
                throw new RuntimeException("Execute failed: [" . $stmt->errno . "] " . $stmt->error);
            }
            $row = $stmt->get_result()->fetch_assoc();
        } finally {
            $stmt->close();
            $mysqli->close();
        }

        return $row === null ? null : (string) $row["value"];
    }

    public function set(string $key, string $value): void
    {
        $name = self::KEY_PREFIX . $key;

        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("INSERT INTO `settings` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
        $stmt->bind_param("sss", $name, $value, $value);

        try {
            if (!$stmt->execute()) {
                throw new RuntimeException("Execute failed: [" . $stmt->errno . "] " . $stmt->error);
            }
        } finally {
            $stmt->close();
            $mysqli->close();
        }
    }
}
