<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\CryptoHelper;
use GuiBranco\GStracciniBot\Library\DatabaseException;
use GuiBranco\GStracciniBot\Library\OwnerCredentials;
use GuiBranco\GStracciniBot\Library\OwnerTokenResolver;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Resolver whose database access is replaced by an in-memory candidate.
 */
class InMemoryOwnerTokenResolver extends OwnerTokenResolver
{
    /**
     * The user id of the in-memory candidate.
     */
    public const USER_ID = 42;

    /**
     * The candidate findCandidate() returns.
     *
     * @var stdClass|null
     */
    public ?stdClass $candidate = null;

    /**
     * The lookups made, as "installationId:repositoryOwner".
     *
     * @var string[]
     */
    public array $lookups = [];

    /**
     * The user ids whose token was recorded as used.
     *
     * @var integer[]
     */
    public array $used = [];

    /**
     * The errors recorded, keyed by user id.
     *
     * @var string[]
     */
    public array $errors = [];

    /**
     * Whether findCandidate() fails as the database would.
     *
     * @var boolean
     */
    public bool $databaseDown = false;


    /**
     * Returns the in-memory candidate.
     *
     * @param int    $installationId  The installation looked up.
     * @param string $repositoryOwner The preferred login.
     *
     * @return stdClass|null The candidate.
     */
    protected function findCandidate(int $installationId, string $repositoryOwner): ?stdClass
    {
        $this->lookups[] = "{$installationId}:{$repositoryOwner}";
        if ($this->databaseDown === true) {
            throw new DatabaseException("Failed to connect to MySQL");
        }

        return $this->candidate;

    }//end findCandidate()


    /**
     * Keeps the user id instead of writing it to the database.
     *
     * @param int $userId The user whose integration was used.
     *
     * @return void
     */
    protected function recordUsage(int $userId): void
    {
        $this->used[] = $userId;

    }//end recordUsage()


    /**
     * Keeps the error instead of writing it to the database.
     *
     * @param int    $userId         The user whose integration failed.
     * @param string $encryptedToken The stored value that failed to decrypt.
     * @param string $error          Why the token can't be used.
     *
     * @return void
     */
    protected function recordError(int $userId, string $encryptedToken, string $error): void
    {
        $this->errors[$userId] = "{$error} ({$encryptedToken})";

    }//end recordError()


}//end class


/**
 * Tests the owner token resolution: stored integration, fallback and failures.
 */
class OwnerTokenResolverTest extends TestCase
{
    /**
     * The service-wide token given to the resolver.
     */
    private const FALLBACK_TOKEN = "fallback-token";

    /**
     * The login of the service-wide token.
     */
    private const FALLBACK_LOGIN = "fallback-login";

    /**
     * Encrypts the stored tokens of the tests.
     *
     * @var CryptoHelper
     */
    private CryptoHelper $crypto;


    /**
     * Builds the CryptoHelper with a test key.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->crypto = new CryptoHelper(base64_encode(str_repeat("k", 32)));

    }//end setUp()


    /**
     * Builds a resolver with the fallback configured.
     *
     * @param string $fallbackToken The service-wide token.
     *
     * @return InMemoryOwnerTokenResolver The resolver.
     */
    private function createResolver(string $fallbackToken): InMemoryOwnerTokenResolver
    {
        return new InMemoryOwnerTokenResolver($this->crypto, $fallbackToken, self::FALLBACK_LOGIN);

    }//end createResolver()


    /**
     * Builds a stored candidate whose token was encrypted with the given helper.
     *
     * @param CryptoHelper $crypto The helper that encrypted the stored token.
     *
     * @return stdClass The candidate.
     */
    private function createCandidate(CryptoHelper $crypto): stdClass
    {
        $candidate = new stdClass();
        $candidate->userId = InMemoryOwnerTokenResolver::USER_ID;
        $candidate->login = "octocat";
        $candidate->encryptedToken = $crypto->encrypt("stored-token");
        return $candidate;

    }//end createCandidate()


    /**
     * A stored integration of the installation wins over the fallback.
     *
     * @return void
     */
    public function testUsesStoredIntegration(): void
    {
        $resolver = $this->createResolver(self::FALLBACK_TOKEN);
        $resolver->candidate = $this->createCandidate($this->crypto);

        $owner = $resolver->resolve(123, "octo-org");

        $this->assertNotNull($owner);
        $this->assertSame("stored-token", $owner->token);
        $this->assertSame("octocat", $owner->login);
        $this->assertSame(OwnerCredentials::SOURCE_DATABASE, $owner->source);
        $this->assertSame(["123:octo-org"], $resolver->lookups);
        $this->assertSame([InMemoryOwnerTokenResolver::USER_ID], $resolver->used);

    }//end testUsesStoredIntegration()


    /**
     * Without a stored integration, the service-wide token is used.
     *
     * @return void
     */
    public function testFallsBackWithoutIntegration(): void
    {
        $resolver = $this->createResolver(self::FALLBACK_TOKEN);

        $owner = $resolver->resolve(123, "octo-org");

        $this->assertNotNull($owner);
        $this->assertSame(self::FALLBACK_TOKEN, $owner->token);
        $this->assertSame(self::FALLBACK_LOGIN, $owner->login);
        $this->assertSame(OwnerCredentials::SOURCE_FALLBACK, $owner->source);

    }//end testFallsBackWithoutIntegration()


    /**
     * A token that can't be decrypted is recorded as an error and skipped.
     *
     * @return void
     */
    public function testRecordsUndecryptableToken(): void
    {
        $resolver = $this->createResolver(self::FALLBACK_TOKEN);
        $resolver->candidate = $this->createCandidate(new CryptoHelper(base64_encode(str_repeat("x", 32))));

        $owner = $resolver->resolve(123, "octo-org");

        $this->assertSame(OwnerCredentials::SOURCE_FALLBACK, $owner?->source);
        $this->assertStringContainsString($resolver->candidate->encryptedToken, $resolver->errors[InMemoryOwnerTokenResolver::USER_ID]);
        $this->assertSame([], $resolver->used);

    }//end testRecordsUndecryptableToken()


    /**
     * A database failure falls back to the service-wide token instead of failing.
     *
     * @return void
     */
    public function testFallsBackWhenDatabaseFails(): void
    {
        $resolver = $this->createResolver(self::FALLBACK_TOKEN);
        $resolver->databaseDown = true;

        $owner = $resolver->resolve(123, "octo-org");

        $this->assertSame(OwnerCredentials::SOURCE_FALLBACK, $owner?->source);
        $this->assertSame(self::FALLBACK_TOKEN, $owner?->token);

    }//end testFallsBackWhenDatabaseFails()


    /**
     * Without a stored integration or a service-wide token, there is no owner.
     *
     * @return void
     */
    public function testReturnsNullWithoutAnySource(): void
    {
        $resolver = $this->createResolver("");

        $this->assertNull($resolver->resolve(123, "octo-org"));

    }//end testReturnsNullWithoutAnySource()


}//end class
