<?php


use GuiBranco\Pancake\GUIDv4;

/**
 * Push handler class for managing GitHub push operations
 */
class PushHandler
{
    private $dbHandler;
    private const TABLE_NAME = 'github_pushes';

    public function __construct(DatabaseHandler $dbHandler)
    {
        $this->dbHandler = $dbHandler;
    }

    /**
     * Get pending pushes for processing
     */
    public function getPendingPushes(?string $where = null): ?array
    {
        return $this->dbHandler->readTable(self::TABLE_NAME, $where);
    }

    /**
     * Update push processing state
     */
    public function updateProcessingState(int $sequence): bool
    {
        return $this->dbHandler->updateProcessingState(self::TABLE_NAME, $sequence);
    }

    /**
     * Finalize push processing
     */
    public function finalizeProcessing(int $sequence): bool
    {
        return $this->dbHandler->finalizeProcessing(self::TABLE_NAME, $sequence);
    }

    /**
     * Insert or update push record
     */
    public function upsertPush($commit): bool
    {
        $mysqli = $this->dbHandler->connect();

        // Check if push exists
        $existingSequence = $this->findExistingPush($mysqli, $commit->HeadCommitId);

        if ($existingSequence) {
            return $this->updateExistingPush($mysqli, $existingSequence);
        } else {
            return $this->insertNewPush($mysqli, $commit);
        }
    }

    /**
     * Find existing push by HeadCommitId
     */
    private function findExistingPush(mysqli $mysqli, string $headCommitId): ?int
    {
        $sql = "SELECT Sequence FROM " . self::TABLE_NAME . " WHERE HeadCommitId = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("s", $headCommitId);

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row["Sequence"] : null;
    }

    /**
     * Update existing push
     */
    private function updateExistingPush(mysqli $mysqli, int $sequence): bool
    {
        $sql = "UPDATE " . self::TABLE_NAME . " SET ProcessingState = 'RE_REQUESTED', ProcessingDate = NULL WHERE Sequence = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $sequence);

        $success = $stmt->execute();
        if (!$success) {
            throw new Exception("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
        }

        $stmt->close();
        $mysqli->close();

        return $success;
    }

    /**
     * Insert new push record
     */
    private function insertNewPush(mysqli $mysqli, $commit): bool
    {
        $fields = [
            "DeliveryId", "HookId", "TargetId", "TargetType", "RepositoryOwner", "RepositoryName",
            "Ref", "HeadCommitId", "HeadCommitTreeId", "HeadCommitMessage", "HeadCommitTimestamp",
            "HeadCommitAuthorName", "HeadCommitAuthorEmail", "HeadCommitCommitterName",
            "HeadCommitCommitterEmail", "InstallationId"
        ];

        $sql = "INSERT INTO " . self::TABLE_NAME . " (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(
            'siissssssssssssi',
            GUIDv4::random(), // Generate new GUID for DeliveryId
            $commit->HookId,
            $commit->TargetId,
            $commit->TargetType,
            $commit->RepositoryOwner,
            $commit->RepositoryName,
            $commit->Ref,
            $commit->HeadCommitId,
            $commit->HeadCommitTreeId,
            $commit->HeadCommitMessage,
            $commit->HeadCommitTimestamp,
            $commit->HeadCommitAuthorName,
            $commit->HeadCommitAuthorEmail,
            $commit->HeadCommitCommitterName,
            $commit->HeadCommitCommitterEmail,
            $commit->InstallationId
        );

        $success = $stmt->execute();
        if (!$success) {
            throw new Exception("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
        }

        $stmt->close();
        $mysqli->close();

        return $success;
    }
}
