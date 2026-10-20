<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use RuntimeException;

/**
 * Shared plumbing of the sync repositories (InstallationsSyncRepository,
 * RepositoriesSyncRepository): one connection per call, opened through the
 * global connectToDatabase() like the rest of the service, and closed with
 * the statement.
 */
abstract class SyncRepositoryBase
{
    /**
     * Executes a prepared write, closes the statement and the connection,
     * and returns the number of rows changed.
     */
    protected function executeAndClose(mysqli_stmt $stmt, mysqli $mysqli): int
    {
        try {
            if (!$stmt->execute()) {
                throw new RuntimeException("Execute failed: [" . $stmt->errno . "] " . $stmt->error);
            }

            return $stmt->affected_rows;
        } finally {
            $stmt->close();
            $mysqli->close();
        }
    }

    /**
     * Runs a plain query, closing the connection and throwing when it fails.
     */
    protected function queryOrFail(mysqli $mysqli, string $sql): mysqli_result
    {
        $result = $mysqli->query($sql);
        if ($result === false) {
            $error = $mysqli->error;
            $mysqli->close();
            throw new RuntimeException("Query failed: {$error}");
        }

        return $result;
    }
}
