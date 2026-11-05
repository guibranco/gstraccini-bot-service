<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

use mysqli;
use mysqli_stmt;
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
            $this->execute($stmt);
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
        $this->executeAndClose($stmt, $mysqli);
    }

    /**
     * The unique key on `name` makes this atomic: for a key that isn't set
     * yet, only one of several concurrent inserts can succeed; for a set
     * key, the conditional update changes the row only while it still holds
     * the expected value.
     */
    public function compareAndSet(string $key, ?string $expected, string $value): bool
    {
        $name = self::KEY_PREFIX . $key;
        $mysqli = connectToDatabase();

        if ($expected === null) {
            $stmt = $mysqli->prepare("INSERT IGNORE INTO `settings` (`name`, `value`) VALUES (?, ?)");
            $stmt->bind_param("ss", $name, $value);
        } else {
            $stmt = $mysqli->prepare("UPDATE `settings` SET `value` = ? WHERE `name` = ? AND `value` = ?");
            $stmt->bind_param("sss", $value, $name, $expected);
        }

        return $this->executeAndClose($stmt, $mysqli) > 0;
    }

    private function execute(mysqli_stmt $stmt): void
    {
        if (!$stmt->execute()) {
            throw new RuntimeException("Execute failed: [" . $stmt->errno . "] " . $stmt->error);
        }
    }

    private function executeAndClose(mysqli_stmt $stmt, mysqli $mysqli): int
    {
        try {
            $this->execute($stmt);

            return $stmt->affected_rows;
        } finally {
            $stmt->close();
            $mysqli->close();
        }
    }
}
