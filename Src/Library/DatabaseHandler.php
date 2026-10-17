<?php

/**
 * Database connection handler (with retry) used by the Src/api/v1 endpoints.
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
}
