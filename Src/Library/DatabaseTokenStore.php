<?php

namespace GuiBranco\GStracciniBot\Library;

use stdClass;

/**
 * Keeps installation access tokens in the `github_installation_tokens` table,
 * shared by every process and host using the database. Tokens are bearer
 * credentials, so they are stored encrypted with CryptoHelper, never in plain
 * text. The table is created by a migration in the webhooks repository.
 */
class DatabaseTokenStore
{
    /**
     * The table holding the tokens.
     */
    private const TABLE = "github_installation_tokens";

    /**
     * Encrypts and decrypts the stored tokens.
     *
     * @var CryptoHelper
     */
    private CryptoHelper $crypto;


    /**
     * Sets up the store.
     *
     * @param CryptoHelper $crypto Encrypts and decrypts the stored tokens.
     *
     * @return void
     */
    public function __construct(CryptoHelper $crypto)
    {
        $this->crypto = $crypto;

    }//end __construct()


    /**
     * Reads and decrypts the token of a key.
     *
     * @param string $key The cache key.
     *
     * @return InstallationToken|null Null when none is stored.
     *
     * @throws DatabaseException When the database can't be read.
     * @throws \RuntimeException When the stored token can't be decrypted (another key, corrupted).
     */
    public function read(string $key): ?InstallationToken
    {
        $row = $this->readRow($key);
        if ($row === null) {
            return null;
        }

        $token = $this->crypto->decrypt((string) $row->encryptedToken);
        return new InstallationToken($token, (int) $row->installationId, (int) $row->expiresAt);

    }//end read()


    /**
     * Encrypts and stores the token of a key, and clears the rows already expired.
     *
     * @param string            $key   The cache key.
     * @param InstallationToken $token The token.
     * @param int               $now   The current Unix time.
     *
     * @return void
     *
     * @throws DatabaseException When the database can't be written.
     */
    public function write(string $key, InstallationToken $token, int $now): void
    {
        $encrypted = $this->crypto->encrypt($token->token);
        $this->writeRow($key, $token->installationId, $encrypted, $token->expiresAt);

        // A row is only rewritten by its own key, so expired ones would pile up.
        $this->deleteExpiredRows($now);

    }//end write()


    /**
     * Deletes the token of a key.
     *
     * @param string $key The cache key.
     *
     * @return void
     *
     * @throws DatabaseException When the database can't be written.
     */
    public function delete(string $key): void
    {
        $this->deleteRow($key);

    }//end delete()


    /**
     * Reads the stored row of a key.
     *
     * @param string $key The cache key.
     *
     * @return stdClass|null The row (installationId, encryptedToken, expiresAt), or null when none is stored.
     *
     * @throws DatabaseException When the database can't be read.
     */
    protected function readRow(string $key): ?stdClass
    {
        $table = self::TABLE;
        $sql = "SELECT InstallationId AS installationId, EncryptedToken AS encryptedToken, ExpiresAt AS expiresAt
                FROM {$table} WHERE CacheKey = ?";

        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("s", $key);
        if ($stmt->execute() === false) {
            $failure = statementFailed($stmt);
            $stmt->close();
            $mysqli->close();
            throw $failure;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_object();
        $result->close();
        $stmt->close();
        $mysqli->close();

        if ($row instanceof stdClass) {
            return $row;
        }

        return null;

    }//end readRow()


    /**
     * Inserts or replaces the stored row of a key.
     *
     * @param string $key            The cache key.
     * @param int    $installationId The installation.
     * @param string $encryptedToken The token, encrypted.
     * @param int    $expiresAt      The Unix time the token expires at.
     *
     * @return void
     *
     * @throws DatabaseException When the database can't be written.
     */
    protected function writeRow(string $key, int $installationId, string $encryptedToken, int $expiresAt): void
    {
        $table = self::TABLE;
        $sql = "INSERT INTO {$table} (CacheKey, InstallationId, EncryptedToken, ExpiresAt) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE InstallationId = VALUES(InstallationId), EncryptedToken = VALUES(EncryptedToken),
                ExpiresAt = VALUES(ExpiresAt), UpdatedAt = CURRENT_TIMESTAMP";

        $this->execute($sql, "sisi", $key, $installationId, $encryptedToken, $expiresAt);

    }//end writeRow()


    /**
     * Deletes the stored row of a key.
     *
     * @param string $key The cache key.
     *
     * @return void
     *
     * @throws DatabaseException When the database can't be written.
     */
    protected function deleteRow(string $key): void
    {
        $table = self::TABLE;
        $this->execute("DELETE FROM {$table} WHERE CacheKey = ?", "s", $key);

    }//end deleteRow()


    /**
     * Deletes the rows whose token has expired.
     *
     * @param int $now The current Unix time.
     *
     * @return void
     *
     * @throws DatabaseException When the database can't be written.
     */
    protected function deleteExpiredRows(int $now): void
    {
        $table = self::TABLE;
        $this->execute("DELETE FROM {$table} WHERE ExpiresAt < ?", "i", $now);

    }//end deleteExpiredRows()


    /**
     * Runs a statement that returns no rows.
     *
     * @param string $sql       The statement.
     * @param string $types     The bind_param() types of the values.
     * @param mixed  ...$values The values bound to the placeholders.
     *
     * @return void
     *
     * @throws DatabaseException When the statement fails.
     */
    protected function execute(string $sql, string $types, mixed ...$values): void
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $executed = $stmt->execute();
        $failure = null;
        if ($executed === false) {
            $failure = statementFailed($stmt);
        }

        $stmt->close();
        $mysqli->close();

        if ($failure !== null) {
            throw $failure;
        }

    }//end execute()


}//end class
