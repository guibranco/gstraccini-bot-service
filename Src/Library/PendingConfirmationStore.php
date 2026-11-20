<?php

namespace GuiBranco\GStracciniBot\Library;

use mysqli_stmt;

/**
 * Keeps track of the bot comments waiting for a user confirmation in the
 * `pending_confirmations` table (created by a migration in the webhooks
 * repository): recorded when the comment is posted, resolved when the choice is
 * applied or no longer needed, cancelled when the pull request is closed.
 *
 * Tracking must never break the flow it tracks, so callers go through
 * PendingConfirmationTracker, which logs and swallows database failures.
 */
class PendingConfirmationStore
{
    private const TABLE = "pending_confirmations";

    /**
     * Records a pending confirmation; does nothing when its comment is already recorded.
     *
     * @param PendingConfirmation $confirmation The confirmation (its sequence is ignored).
     *
     * @return void
     *
     * @throws DatabaseException When the database can't be written.
     */
    public function record(PendingConfirmation $confirmation): void
    {
        $table = self::TABLE;
        $title = $confirmation->title === null ? null : mb_substr($confirmation->title, 0, 255);
        $sql = "INSERT IGNORE INTO {$table}
            (Kind, RepositoryOwner, RepositoryName, InstallationId, PullRequestNumber, PullRequestTitle,
             PullRequestAuthor, CommentId, Url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $this->execute(
            $sql,
            "sssiissis",
            $confirmation->kind,
            $confirmation->owner,
            $confirmation->repo,
            $confirmation->installationId,
            $confirmation->pullRequestNumber,
            $title,
            $confirmation->author,
            $confirmation->commentId,
            $confirmation->url
        );
    }

    /**
     * Resolves the pending confirmation of a comment.
     *
     * @param int    $commentId  The bot's comment.
     * @param string $resolution What was decided (e.g. `minor`, `none`, `applied`).
     *
     * @return int How many rows changed (0 or 1).
     *
     * @throws DatabaseException When the database can't be written.
     */
    public function resolveByComment(int $commentId, string $resolution): int
    {
        return $this->close("CommentId = ?", PendingConfirmation::STATUS_RESOLVED, $resolution, "i", $commentId);
    }

    /**
     * Resolves the pending confirmations of one kind on a pull request.
     *
     * @param string $owner      Repository owner.
     * @param string $repo       Repository name.
     * @param int    $number     Pull request number.
     * @param string $kind       One of the PendingConfirmation::KIND_* constants.
     * @param string $resolution Why.
     *
     * @return int How many rows changed.
     *
     * @throws DatabaseException When the database can't be written.
     */
    public function resolveForPullRequest(string $owner, string $repo, int $number, string $kind, string $resolution): int
    {
        return $this->close(
            "RepositoryOwner = ? AND RepositoryName = ? AND PullRequestNumber = ? AND Kind = ?",
            PendingConfirmation::STATUS_RESOLVED,
            $resolution,
            "ssis",
            $owner,
            $repo,
            $number,
            $kind
        );
    }

    /**
     * Cancels every pending confirmation of a pull request.
     *
     * @param string $owner      Repository owner.
     * @param string $repo       Repository name.
     * @param int    $number     Pull request number.
     * @param string $resolution Why (merged, closed).
     *
     * @return int How many rows changed.
     *
     * @throws DatabaseException When the database can't be written.
     */
    public function cancelForPullRequest(string $owner, string $repo, int $number, string $resolution): int
    {
        return $this->close(
            "RepositoryOwner = ? AND RepositoryName = ? AND PullRequestNumber = ?",
            PendingConfirmation::STATUS_CANCELLED,
            $resolution,
            "ssi",
            $owner,
            $repo,
            $number
        );
    }

    /**
     * Cancels the pending confirmation of a comment.
     *
     * @param int    $commentId  The bot's comment.
     * @param string $resolution Why.
     *
     * @return int How many rows changed (0 or 1).
     *
     * @throws DatabaseException When the database can't be written.
     */
    public function cancelByComment(int $commentId, string $resolution): int
    {
        return $this->close("CommentId = ?", PendingConfirmation::STATUS_CANCELLED, $resolution, "i", $commentId);
    }

    /**
     * The pending confirmations due for a reminder: created, and last reminded, at
     * least `$intervalSeconds` ago, with fewer than `$maxReminders` reminders, oldest first.
     *
     * @param int $intervalSeconds How long to wait before (another) reminder.
     * @param int $maxReminders    How many reminders at most.
     * @param int $limit           How many rows at most.
     *
     * @return PendingConfirmation[]
     *
     * @throws DatabaseException When the database can't be read.
     */
    public function findDueForReminder(int $intervalSeconds, int $maxReminders, int $limit): array
    {
        $table = self::TABLE;
        $sql = "SELECT Sequence, Kind, RepositoryOwner, RepositoryName, InstallationId, PullRequestNumber,
                PullRequestTitle, PullRequestAuthor, CommentId, Url, ReminderCount
            FROM {$table}
            WHERE Status = ?
                AND CreatedAt <= NOW() - INTERVAL ? SECOND
                AND (LastReminderAt IS NULL OR LastReminderAt <= NOW() - INTERVAL ? SECOND)
                AND ReminderCount < ?
            ORDER BY CreatedAt
            LIMIT ?";

        $status = PendingConfirmation::STATUS_PENDING;
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("siiii", $status, $intervalSeconds, $intervalSeconds, $maxReminders, $limit);

        try {
            $this->run($stmt);
            $result = $stmt->get_result();
            $confirmations = [];
            while (($row = $result->fetch_assoc()) !== null) {
                $confirmations[] = new PendingConfirmation(
                    (int) $row["Sequence"],
                    (string) $row["Kind"],
                    (string) $row["RepositoryOwner"],
                    (string) $row["RepositoryName"],
                    (int) $row["InstallationId"],
                    (int) $row["PullRequestNumber"],
                    (int) $row["CommentId"],
                    $row["Url"],
                    $row["PullRequestTitle"],
                    $row["PullRequestAuthor"],
                    (int) $row["ReminderCount"]
                );
            }
            $result->close();
        } finally {
            $stmt->close();
            $mysqli->close();
        }

        return $confirmations;
    }

    /**
     * Counts a reminder posted for a pending confirmation.
     *
     * @param int $sequence The row.
     *
     * @return void
     *
     * @throws DatabaseException When the database can't be written.
     */
    public function markReminded(int $sequence): void
    {
        $table = self::TABLE;
        $this->execute(
            "UPDATE {$table} SET ReminderCount = ReminderCount + 1, LastReminderAt = NOW() WHERE Sequence = ?",
            "i",
            $sequence
        );
    }

    /**
     * Moves the pending rows matching a condition to a final status.
     *
     * @param string $where      The condition, with placeholders.
     * @param string $status     The new status.
     * @param string $resolution Why.
     * @param string $types      bind_param types of the condition's values.
     * @param mixed  ...$values  The condition's values.
     *
     * @return int How many rows changed.
     */
    private function close(string $where, string $status, string $resolution, string $types, mixed ...$values): int
    {
        $table = self::TABLE;
        $pending = PendingConfirmation::STATUS_PENDING;
        $sql = "UPDATE {$table} SET Status = ?, Resolution = ?, ResolvedAt = NOW() WHERE Status = ? AND {$where}";

        return $this->execute($sql, "sss" . $types, $status, $resolution, $pending, ...$values);
    }

    /**
     * Runs a write statement.
     *
     * @return int The affected rows.
     *
     * @throws DatabaseException When it fails.
     */
    private function execute(string $sql, string $types, mixed ...$values): int
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$values);

        try {
            $this->run($stmt);
            return $stmt->affected_rows;
        } finally {
            $stmt->close();
            $mysqli->close();
        }
    }

    /**
     * @throws DatabaseException When the statement fails.
     */
    private function run(mysqli_stmt $stmt): void
    {
        if ($stmt->execute() === false) {
            throw statementFailed($stmt);
        }
    }
}
