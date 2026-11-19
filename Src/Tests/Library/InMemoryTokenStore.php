<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\DatabaseException;
use GuiBranco\GStracciniBot\Library\DatabaseTokenStore;
use stdClass;

/**
 * Database token store whose table is an in-memory array.
 */
class InMemoryTokenStore extends DatabaseTokenStore
{
    /**
     * The error thrown while the database is down.
     */
    public const FAILURE = "Failed to connect to MySQL";

    /**
     * The stored rows (installationId, encryptedToken, expiresAt), keyed by cache key.
     *
     * @var array<string, stdClass>
     */
    public array $rows = [];

    /**
     * Whether every call fails as an unreachable database would.
     *
     * @var boolean
     */
    public bool $databaseDown = false;


    /**
     * Reads the in-memory row.
     *
     * @param string $key The cache key.
     *
     * @return stdClass|null The row.
     */
    protected function readRow(string $key): ?stdClass
    {
        $this->failIfDown();
        $row = ($this->rows[$key] ?? null);
        return $row;

    }//end readRow()


    /**
     * Writes the in-memory row.
     *
     * @param string $key            The cache key.
     * @param int    $installationId The installation.
     * @param string $encryptedToken The encrypted token.
     * @param int    $expiresAt      The expiry.
     *
     * @return void
     */
    protected function writeRow(string $key, int $installationId, string $encryptedToken, int $expiresAt): void
    {
        $this->failIfDown();

        $row = new stdClass();
        $row->installationId = $installationId;
        $row->encryptedToken = $encryptedToken;
        $row->expiresAt = $expiresAt;
        $this->rows[$key] = $row;

    }//end writeRow()


    /**
     * Deletes the in-memory row.
     *
     * @param string $key The cache key.
     *
     * @return void
     */
    protected function deleteRow(string $key): void
    {
        $this->failIfDown();
        unset($this->rows[$key]);

    }//end deleteRow()


    /**
     * Deletes the expired in-memory rows.
     *
     * @param int $now The current Unix time.
     *
     * @return void
     */
    protected function deleteExpiredRows(int $now): void
    {
        $this->failIfDown();
        foreach ($this->rows as $key => $row) {
            if ($row->expiresAt < $now) {
                unset($this->rows[$key]);
            }
        }

    }//end deleteExpiredRows()


    /**
     * Throws while the database is down.
     *
     * @return void
     *
     * @throws DatabaseException While the database is down.
     */
    private function failIfDown(): void
    {
        if ($this->databaseDown === true) {
            throw new DatabaseException(self::FAILURE);
        }

    }//end failIfDown()


}//end class
