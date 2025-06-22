<?php


/**
 * Pull Request handler class for managing GitHub pull request operations
 */
class PullRequestHandler
{
    private $dbHandler;
    private const TABLE_NAME = 'github_pull_requests';
    
    public function __construct(DatabaseHandler $dbHandler)
    {
        $this->dbHandler = $dbHandler;
    }
    
    /**
     * Get pending pull requests for processing
     */
    public function getPendingPullRequests(?string $where = null): ?array
    {
        return $this->dbHandler->readTable(self::TABLE_NAME, $where);
    }
    
    /**
     * Update pull request processing state
     */
    public function updateProcessingState(int $sequence): bool
    {
        return $this->dbHandler->updateProcessingState(self::TABLE_NAME, $sequence);
    }
    
    /**
     * Finalize pull request processing
     */
    public function finalizeProcessing(int $sequence): bool
    {
        return $this->dbHandler->finalizeProcessing(self::TABLE_NAME, $sequence);
    }
    
    /**
     * Close pull request
     */
    public function closePullRequest(int $sequence): bool
    {
        return $this->dbHandler->updateStateToClose('pull_requests', $sequence);
    }
    
    /**
     * Insert or update pull request record
     */
    public function upsertPullRequest($pullRequest): bool
    {
        $mysqli = $this->dbHandler->connect();
        
        // Check if pull request exists
        $existingSequence = $this->findExistingPullRequest($mysqli, $pullRequest->NodeId);
        
        if ($existingSequence) {
            return $this->updateExistingPullRequest($mysqli, $existingSequence);
        } else {
            return $this->insertNewPullRequest($mysqli, $pullRequest);
        }
    }
    
    /**
     * Find existing pull request by NodeId
     */
    private function findExistingPullRequest(mysqli $mysqli, string $nodeId): ?int
    {
        $sql = "SELECT Sequence FROM " . self::TABLE_NAME . " WHERE NodeId = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("s", $nodeId);
        
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row ? (int)$row["Sequence"] : null;
    }
    
    /**
     * Update existing pull request
     */
    private function updateExistingPullRequest(mysqli $mysqli, int $sequence): bool
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
     * Insert new pull request
     */
    private function insertNewPullRequest(mysqli $mysqli, $pullRequest): bool
    {
        $fields = [
            "DeliveryId", "HookId", "TargetId", "TargetType", "RepositoryOwner",
            "RepositoryName", "Id", "Number", "Sender", "NodeId", "Title", "Ref", "InstallationId"
        ];
        
        $sql = "INSERT INTO " . self::TABLE_NAME . " (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(
            'siisssiissssi',
            $pullRequest->DeliveryId,
            $pullRequest->HookId,
            $pullRequest->TargetId,
            $pullRequest->TargetType,
            $pullRequest->RepositoryOwner,
            $pullRequest->RepositoryName,
            $pullRequest->Id,
            $pullRequest->Number,
            $pullRequest->Sender,
            $pullRequest->NodeId,
            $pullRequest->Title,
            $pullRequest->Ref,
            $pullRequest->InstallationId
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