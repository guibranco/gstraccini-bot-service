<?php

/**
 * Database handler class for managing MySQL connections and basic operations
 */
class DatabaseHandler
{
    private $host;
    private $user;
    private $password;
    private $database;
    
    public function __construct($host, $user, $password, $database)
    {
        $this->host = $host;
        $this->user = $user;
        $this->password = $password;
        $this->database = $database;
    }
    
    /**
     * Establish database connection with retry mechanism
     */
    public function connect($isRetry = false): mysqli
    {
        try {
            $mysqli = new mysqli($this->host, $this->user, $this->password, $this->database);
            
            if ($mysqli->connect_errno && !$isRetry) {
                sleep(10);
                return $this->connect(true);
            } elseif ($mysqli->connect_errno) {
                throw new Exception("Failed to connect to MySQL: " . $mysqli->connect_error);
            }
            
            $mysqli->set_charset("utf8mb4");
            return $mysqli;
            
        } catch (Exception $e) {
            if ($isRetry) {
                throw $e;
            }
            sleep(10);
            return $this->connect(true);
        }
    }
    
    /**
     * Read records from specified table with optional where clause
     */
    public function readTable(string $tableName, ?string $where = null): ?array
    {
        $mysqli = $this->connect();
        $defaultWhere = "ProcessingState IN ('NEW', 'RE_REQUESTED', 'UPDATED') ORDER BY Sequence ASC LIMIT 10";
        
        $sql = "SELECT * FROM " . $tableName . " WHERE ";
        $sql .= $where ?? $defaultWhere;
        
        $result = $mysqli->query($sql);
        
        if (!$result) {
            $mysqli->close();
            return null;
        }
        
        $data = [];
        while ($obj = $result->fetch_object()) {
            $data[] = $obj;
        }
        
        $result->close();
        $mysqli->close();
        
        return $data;
    }
    
    /**
     * Update processing state for a specific record
     */
    public function updateProcessingState(string $tableName, int $sequence): bool
    {
        $mysqli = $this->connect();
        $sql = "UPDATE " . $tableName . " SET ProcessingState = 'PROCESSING', ProcessingDate = NOW() WHERE Sequence = ?";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $sequence);
        
        $succeeded = false;
        if ($stmt->execute()) {
            $succeeded = $stmt->affected_rows === 1;
        }
        
        $stmt->close();
        $mysqli->close();
        
        return $succeeded;
    }
    
    /**
     * Finalize processing for a specific record
     */
    public function finalizeProcessing(string $tableName, int $sequence): bool
    {
        $mysqli = $this->connect();
        $sql = "UPDATE " . $tableName . " SET ProcessingState = 'PROCESSED', ProcessedDate = NOW() 
                WHERE Sequence = ? AND ProcessingState = 'PROCESSING' AND ProcessingDate IS NOT NULL";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $sequence);
        
        $succeeded = false;
        if ($stmt->execute()) {
            $succeeded = $stmt->affected_rows === 1;
        }
        
        $stmt->close();
        $mysqli->close();
        
        return $succeeded;
    }
    
    /**
     * Update state to closed for GitHub entities
     */
    public function updateStateToClose(string $table, int $sequence): bool
    {
        $mysqli = $this->connect();
        $sql = "UPDATE github_{$table} SET State = 'CLOSED' WHERE Sequence = ? AND State = 'OPEN'";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $sequence);
        
        $succeeded = false;
        if ($stmt->execute()) {
            $succeeded = $stmt->affected_rows === 1;
        }
        
        $stmt->close();
        $mysqli->close();
        
        return $succeeded;
    }
}