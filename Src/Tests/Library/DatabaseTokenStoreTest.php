<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\CryptoHelper;
use GuiBranco\GStracciniBot\Library\InstallationToken;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseTokenStoreTest extends TestCase
{
    /**
     * The current Unix time of the tests.
     */
    private const NOW = 1800000000;

    /**
     * Encrypts the stored tokens.
     *
     * @var CryptoHelper
     */
    private CryptoHelper $crypto;

    /**
     * The store under test.
     *
     * @var InMemoryTokenStore
     */
    private InMemoryTokenStore $store;


    /**
     * Builds the store with a random key.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->crypto = new CryptoHelper(base64_encode(random_bytes(32)));
        $this->store = new InMemoryTokenStore($this->crypto);

    }//end setUp()


    /**
     * The token is stored encrypted, with its installation and expiry.
     *
     * @return void
     */
    public function testTokenIsStoredEncrypted(): void
    {
        $expiresAt = (self::NOW + 3600);
        $this->store->write("key", new InstallationToken("ghs_secret", 7, $expiresAt), self::NOW);

        $row = $this->store->rows["key"];
        $this->assertSame(7, $row->installationId);
        $this->assertSame($expiresAt, $row->expiresAt);
        $this->assertStringNotContainsString("ghs_secret", $row->encryptedToken);
        $this->assertSame("ghs_secret", $this->crypto->decrypt($row->encryptedToken));

        $read = $this->store->read("key");
        $this->assertSame("ghs_secret", $read->token);
        $this->assertSame(7, $read->installationId);
        $this->assertSame($expiresAt, $read->expiresAt);
        $this->assertNull($this->store->read("other"));

    }//end testTokenIsStoredEncrypted()


    /**
     * A token encrypted with another key can't be read.
     *
     * @return void
     */
    public function testOtherKeyTokenIsNotRead(): void
    {
        $otherKey = new CryptoHelper(base64_encode(random_bytes(32)));
        $other = new InMemoryTokenStore($otherKey);
        $other->write("key", new InstallationToken("ghs_secret", 7, (self::NOW + 3600)), self::NOW);
        $this->store->rows = $other->rows;

        $this->expectException(RuntimeException::class);
        $this->store->read("key");

    }//end testOtherKeyTokenIsNotRead()


    /**
     * Writing a token clears the expired rows, and delete removes a key.
     *
     * @return void
     */
    public function testExpiredRowsAreCleared(): void
    {
        $this->store->write("old", new InstallationToken("ghs_old", 1, (self::NOW - 1)), (self::NOW - 3600));
        $this->store->write("new", new InstallationToken("ghs_new", 2, (self::NOW + 3600)), self::NOW);

        $this->assertArrayNotHasKey("old", $this->store->rows);
        $this->assertArrayHasKey("new", $this->store->rows);

        $this->store->delete("new");
        $this->assertSame([], $this->store->rows);

    }//end testExpiredRowsAreCleared()


}//end class
