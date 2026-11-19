<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\CryptoHelper;
use GuiBranco\GStracciniBot\Library\DatabaseException;
use GuiBranco\GStracciniBot\Library\InstallationTokenCache;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Cache whose database layer is an in-memory table.
 */
class InMemoryInstallationTokenCache extends InstallationTokenCache
{
    /**
     * The stored rows, keyed by cache key.
     *
     * @var array<string, array{installationId: int, encryptedToken: string, expiresAt: int}>
     */
    public array $rows = [];

    /**
     * Whether the database calls fail as an unreachable database would.
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
    protected function readStored(string $key): ?stdClass
    {
        $this->failIfDown();
        if (isset($this->rows[$key]) === false) {
            return null;
        }

        $row = new stdClass();
        $row->encryptedToken = $this->rows[$key]["encryptedToken"];
        $row->expiresAt = $this->rows[$key]["expiresAt"];
        return $row;

    }//end readStored()


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
    protected function writeStored(string $key, int $installationId, string $encryptedToken, int $expiresAt): void
    {
        $this->failIfDown();
        $this->rows[$key] = ["installationId" => $installationId, "encryptedToken" => $encryptedToken, "expiresAt" => $expiresAt];

    }//end writeStored()


    /**
     * Deletes the in-memory row.
     *
     * @param string $key The cache key.
     *
     * @return void
     */
    protected function deleteStored(string $key): void
    {
        $this->failIfDown();
        unset($this->rows[$key]);

    }//end deleteStored()


    /**
     * Throws when the database is down.
     *
     * @return void
     */
    private function failIfDown(): void
    {
        if ($this->databaseDown === true) {
            throw new DatabaseException("Failed to connect to MySQL");
        }

    }//end failIfDown()


}//end class

class InstallationTokenCacheTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const ONE_HOUR = 3600;

    private CryptoHelper $crypto;

    private int $now;

    private int $mints;


    protected function setUp(): void
    {
        $this->crypto = new CryptoHelper(base64_encode(random_bytes(32)));
        $this->now = self::NOW;
        $this->mints = 0;

    }//end setUp()


    private function buildCache(?CryptoHelper $crypto = null, bool $withCrypto = true): InMemoryInstallationTokenCache
    {
        $crypto = $withCrypto === true ? ($crypto ?? $this->crypto) : null;
        return new InMemoryInstallationTokenCache($crypto, false, fn (): int => $this->now);

    }//end buildCache()


    private function mint(int $lifetime = self::ONE_HOUR): callable
    {
        return function () use ($lifetime): array {
            $this->mints++;
            return ["token" => "ghs_token_" . $this->mints, "expiresAt" => $this->now + $lifetime];
        };

    }//end mint()


    public function testKeyDependsOnInstallationAndPermissions(): void
    {
        $key = InstallationTokenCache::buildKey("1", ["issues" => "write", "contents" => "read"]);

        $this->assertSame(64, strlen($key));
        $this->assertSame($key, InstallationTokenCache::buildKey("1", ["contents" => "read", "issues" => "write"]), "Permission order doesn't matter");
        $this->assertNotSame($key, InstallationTokenCache::buildKey("2", ["issues" => "write", "contents" => "read"]));
        $this->assertNotSame($key, InstallationTokenCache::buildKey("1", ["issues" => "read", "contents" => "read"]));
        $this->assertNotSame($key, InstallationTokenCache::buildKey("1", null));
        $this->assertSame(InstallationTokenCache::buildKey("1", null), InstallationTokenCache::buildKey("1", []));

    }//end testKeyDependsOnInstallationAndPermissions()


    public function testReusesTheTokenUntilTheSafetyMargin(): void
    {
        $cache = $this->buildCache();

        $this->assertSame("ghs_token_1", $cache->getOrMint("k", 1, $this->mint()));
        $this->now += (self::ONE_HOUR - InstallationTokenCache::SAFETY_MARGIN_SECONDS - 1);
        $this->assertSame("ghs_token_1", $cache->getOrMint("k", 1, $this->mint()));
        $this->assertSame(1, $this->mints);

        $this->now += 1;
        $this->assertSame("ghs_token_2", $cache->getOrMint("k", 1, $this->mint()), "Within the safety margin a new token is minted");
        $this->assertSame(2, $this->mints);

    }//end testReusesTheTokenUntilTheSafetyMargin()


    public function testAnotherProcessReusesTheStoredToken(): void
    {
        $first = $this->buildCache();
        $first->getOrMint("k", 7, $this->mint());

        // A new request on cPanel: empty process memory, same database.
        $second = $this->buildCache();
        $second->rows = $first->rows;

        $this->assertSame("ghs_token_1", $second->getOrMint("k", 7, $this->mint()));
        $this->assertSame(1, $this->mints);

    }//end testAnotherProcessReusesTheStoredToken()


    public function testStoresTheTokenEncrypted(): void
    {
        $cache = $this->buildCache();
        $cache->getOrMint("k", 7, $this->mint());

        $row = $cache->rows["k"];
        $this->assertSame(7, $row["installationId"]);
        $this->assertSame(self::NOW + self::ONE_HOUR, $row["expiresAt"]);
        $this->assertStringNotContainsString("ghs_token_1", $row["encryptedToken"]);
        $this->assertSame("ghs_token_1", $this->crypto->decrypt($row["encryptedToken"]));

    }//end testStoresTheTokenEncrypted()


    public function testExpiredStoredTokenIsReplaced(): void
    {
        $cache = $this->buildCache();
        $cache->rows["k"] = ["installationId" => 1, "encryptedToken" => $this->crypto->encrypt("old"), "expiresAt" => self::NOW + 60];

        $this->assertSame("ghs_token_1", $cache->getOrMint("k", 1, $this->mint()));
        $this->assertSame("ghs_token_1", $this->crypto->decrypt($cache->rows["k"]["encryptedToken"]));

    }//end testExpiredStoredTokenIsReplaced()


    public function testUndecryptableStoredTokenIsReplaced(): void
    {
        $cache = $this->buildCache();
        $otherKey = new CryptoHelper(base64_encode(random_bytes(32)));
        $cache->rows["k"] = ["installationId" => 1, "encryptedToken" => $otherKey->encrypt("rotated"), "expiresAt" => self::NOW + self::ONE_HOUR];

        $this->assertSame("ghs_token_1", $cache->getOrMint("k", 1, $this->mint()));

    }//end testUndecryptableStoredTokenIsReplaced()


    public function testDatabaseDownStillMintsAndCachesInMemory(): void
    {
        $cache = $this->buildCache();
        $cache->databaseDown = true;

        $this->assertSame("ghs_token_1", $cache->getOrMint("k", 1, $this->mint()));
        $this->assertSame("ghs_token_1", $cache->getOrMint("k", 1, $this->mint()));
        $this->assertSame(1, $this->mints);

    }//end testDatabaseDownStillMintsAndCachesInMemory()


    public function testWithoutEncryptionTheDatabaseIsNotUsed(): void
    {
        $cache = $this->buildCache(null, false);

        $this->assertSame("ghs_token_1", $cache->getOrMint("k", 1, $this->mint()));
        $this->assertSame([], $cache->rows, "Tokens are never stored in plain text");
        $this->assertSame("ghs_token_1", $cache->getOrMint("k", 1, $this->mint()), "The process layer still works");

    }//end testWithoutEncryptionTheDatabaseIsNotUsed()


    public function testInvalidatedTokenIsEvictedFromEveryLayer(): void
    {
        $cache = $this->buildCache();
        $cache->getOrMint("k", 1, $this->mint());

        $cache->invalidateToken("ghs_token_1");

        $this->assertArrayNotHasKey("k", $cache->rows);
        $this->assertSame("ghs_token_2", $cache->getOrMint("k", 1, $this->mint()));

    }//end testInvalidatedTokenIsEvictedFromEveryLayer()


    public function testUnknownTokenInvalidationIsIgnored(): void
    {
        $cache = $this->buildCache();
        $cache->getOrMint("k", 1, $this->mint());

        $cache->invalidateToken("a-jwt-or-a-user-token");

        $this->assertSame("ghs_token_1", $cache->getOrMint("k", 1, $this->mint()));
        $this->assertSame(1, $this->mints);

    }//end testUnknownTokenInvalidationIsIgnored()


    public function testMintFailureIsNotCached(): void
    {
        $cache = $this->buildCache();

        try {
            $cache->getOrMint("k", 1, function (): array {
                throw new RuntimeException("GitHub is down");
            });
            $this->fail("The mint failure must propagate");
        } catch (RuntimeException $exception) {
            $this->assertSame("GitHub is down", $exception->getMessage());
        }

        $this->assertSame([], $cache->rows);
        $this->assertSame("ghs_token_1", $cache->getOrMint("k", 1, $this->mint()));

    }//end testMintFailureIsNotCached()


    public function testMalformedMintIsRejected(): void
    {
        $cache = $this->buildCache();

        $this->expectException(RuntimeException::class);
        $cache->getOrMint("k", 1, fn (): array => ["token" => "", "expiresAt" => self::NOW]);

    }//end testMalformedMintIsRejected()


}//end class
