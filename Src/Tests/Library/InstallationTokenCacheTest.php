<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\CryptoHelper;
use GuiBranco\GStracciniBot\Library\InstallationToken;
use GuiBranco\GStracciniBot\Library\InstallationTokenCache;
use GuiBranco\GStracciniBot\Library\InstallationTokenException;
use PHPUnit\Framework\TestCase;

class InstallationTokenCacheTest extends TestCase
{
    /**
     * The Unix time the tests start at.
     */
    private const START = 1800000000;

    /**
     * How long a minted token lives, as GitHub's do.
     */
    private const LIFETIME = 3600;

    /**
     * Encrypts the stored tokens.
     *
     * @var CryptoHelper
     */
    private CryptoHelper $crypto;

    /**
     * The database store shared by the caches of a test.
     *
     * @var InMemoryTokenStore
     */
    private InMemoryTokenStore $store;

    /**
     * The current Unix time.
     *
     * @var integer
     */
    private int $now;

    /**
     * How many tokens were minted.
     *
     * @var integer
     */
    private int $mints;


    /**
     * Starts each test with an empty store and no token minted.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->crypto = new CryptoHelper(base64_encode(random_bytes(32)));
        $this->store = new InMemoryTokenStore($this->crypto);
        $this->now = self::START;
        $this->mints = 0;

    }//end setUp()


    /**
     * The clock of the caches.
     *
     * @return int The current Unix time of the test.
     */
    public function getNow(): int
    {
        return $this->now;

    }//end getNow()


    /**
     * Mints a new token, as GitHub would.
     *
     * @return InstallationToken The token, numbered by mint.
     */
    public function getMintedToken(): InstallationToken
    {
        $this->mints++;
        $expiresAt = ($this->now + self::LIFETIME);
        return new InstallationToken("ghs_token_{$this->mints}", 7, $expiresAt);

    }//end getMintedToken()


    /**
     * A cache over the test's database store, as a new process would build it.
     *
     * @return InstallationTokenCache The cache.
     */
    public function getCache(): InstallationTokenCache
    {
        return new InstallationTokenCache([$this->store], [$this, "getNow"]);

    }//end getCache()


    /**
     * The key depends on the installation and the permissions, not their order.
     *
     * @return void
     */
    public function testKeyDependsOnPermissions(): void
    {
        $cache = $this->getCache();
        $key = $cache->buildKey("1", ["issues" => "write", "contents" => "read"]);

        $this->assertSame(64, strlen($key));
        $this->assertSame($key, $cache->buildKey("1", ["contents" => "read", "issues" => "write"]));
        $this->assertNotSame($key, $cache->buildKey("2", ["issues" => "write", "contents" => "read"]));
        $this->assertNotSame($key, $cache->buildKey("1", ["issues" => "read", "contents" => "read"]));
        $this->assertNotSame($key, $cache->buildKey("1", null));
        $this->assertSame($cache->buildKey("1", null), $cache->buildKey("1", []));

    }//end testKeyDependsOnPermissions()


    /**
     * A token is reused until its safety margin, then a new one is minted.
     *
     * @return void
     */
    public function testReusesTokenUntilSafetyMargin(): void
    {
        $cache = $this->getCache();

        $this->assertSame("ghs_token_1", $cache->getOrMint("key", [$this, "getMintedToken"]));
        $this->now += (self::LIFETIME - InstallationToken::SAFETY_MARGIN_SECONDS - 1);
        $this->assertSame("ghs_token_1", $cache->getOrMint("key", [$this, "getMintedToken"]));
        $this->assertSame(1, $this->mints);

        $this->now += 1;
        $this->assertSame("ghs_token_2", $cache->getOrMint("key", [$this, "getMintedToken"]));
        $this->assertSame(2, $this->mints);

    }//end testReusesTokenUntilSafetyMargin()


    /**
     * A new process (a new request on cPanel) reuses the token stored by another.
     *
     * @return void
     */
    public function testAnotherProcessReusesToken(): void
    {
        $this->getCache()->getOrMint("key", [$this, "getMintedToken"]);

        $this->assertSame("ghs_token_1", $this->getCache()->getOrMint("key", [$this, "getMintedToken"]));
        $this->assertSame(1, $this->mints);
        $this->assertSame(7, $this->store->rows["key"]->installationId);

    }//end testAnotherProcessReusesToken()


    /**
     * A stored token within its safety margin is replaced by a new one.
     *
     * @return void
     */
    public function testExpiredStoredTokenIsReplaced(): void
    {
        $this->store->write("key", new InstallationToken("ghs_old", 7, (self::START + 60)), self::START);

        $this->assertSame("ghs_token_1", $this->getCache()->getOrMint("key", [$this, "getMintedToken"]));
        $this->assertSame("ghs_token_1", $this->store->read("key")->token);

    }//end testExpiredStoredTokenIsReplaced()


    /**
     * A stored token that can't be decrypted (rotated key) is replaced by a new one.
     *
     * @return void
     */
    public function testUndecryptableTokenIsReplaced(): void
    {
        $other = new InMemoryTokenStore(new CryptoHelper(base64_encode(random_bytes(32))));
        $other->write("key", new InstallationToken("ghs_rotated", 7, (self::START + self::LIFETIME)), self::START);
        $this->store->rows = $other->rows;

        $this->assertSame("ghs_token_1", $this->getCache()->getOrMint("key", [$this, "getMintedToken"]));
        $this->assertSame("ghs_token_1", $this->store->read("key")->token);

    }//end testUndecryptableTokenIsReplaced()


    /**
     * With the database down a token is still minted, and reused by the process.
     *
     * @return void
     */
    public function testDatabaseDownStillCaches(): void
    {
        $this->store->databaseDown = true;
        $cache = $this->getCache();

        $this->assertSame("ghs_token_1", $cache->getOrMint("key", [$this, "getMintedToken"]));
        $this->assertSame("ghs_token_1", $cache->getOrMint("key", [$this, "getMintedToken"]));
        $this->assertSame(1, $this->mints);

    }//end testDatabaseDownStillCaches()


    /**
     * Without any store (no encryption key, no APCu) the process still caches.
     *
     * @return void
     */
    public function testProcessCachesWithoutStores(): void
    {
        $cache = new InstallationTokenCache([], [$this, "getNow"]);

        $this->assertSame("ghs_token_1", $cache->getOrMint("key", [$this, "getMintedToken"]));
        $this->assertSame("ghs_token_1", $cache->getOrMint("key", [$this, "getMintedToken"]));
        $this->assertSame(1, $this->mints);

    }//end testProcessCachesWithoutStores()


    /**
     * A token GitHub rejected is evicted everywhere; other tokens are ignored.
     *
     * @return void
     */
    public function testRejectedTokenIsEvicted(): void
    {
        $cache = $this->getCache();
        $cache->getOrMint("key", [$this, "getMintedToken"]);

        $cache->invalidateToken("a-jwt-or-a-user-token");
        $this->assertArrayHasKey("key", $this->store->rows);

        $cache->invalidateToken("ghs_token_1");
        $this->assertArrayNotHasKey("key", $this->store->rows);
        $this->assertSame("ghs_token_2", $cache->getOrMint("key", [$this, "getMintedToken"]));

    }//end testRejectedTokenIsEvicted()


    /**
     * A failed mint propagates and caches nothing.
     *
     * @return void
     */
    public function testMintFailureIsNotCached(): void
    {
        $cache = $this->getCache();
        $failure = null;
        try {
            $cache->getOrMint("key", [$this, "getFailedMint"]);
        } catch (InstallationTokenException $exception) {
            $failure = $exception->getMessage();
        }

        $this->assertSame("GitHub is down", $failure);
        $this->assertSame([], $this->store->rows);
        $this->assertSame("ghs_token_1", $cache->getOrMint("key", [$this, "getMintedToken"]));

    }//end testMintFailureIsNotCached()


    /**
     * A mint that returns something other than a token is rejected.
     *
     * @return void
     */
    public function testMalformedMintIsRejected(): void
    {
        $this->expectException(InstallationTokenException::class);
        $this->getCache()->getOrMint("key", "time");

    }//end testMalformedMintIsRejected()


    /**
     * A mint that fails, as GitHub being down would.
     *
     * @return InstallationToken Never returned.
     *
     * @throws InstallationTokenException Always.
     */
    public function getFailedMint(): InstallationToken
    {
        throw new InstallationTokenException("GitHub is down");

    }//end getFailedMint()


}//end class
