<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\CryptoHelper;
use GuiBranco\GStracciniBot\Library\DatabaseException;
use GuiBranco\GStracciniBot\Library\IntegrationKeyResolver;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Resolver whose database access is replaced by an in-memory candidate.
 */
class InMemoryIntegrationKeyResolver extends IntegrationKeyResolver
{
    /**
     * The user id of the in-memory candidate.
     */
    public const USER_ID = 7;

    /**
     * The candidate findCandidate() returns.
     *
     * @var stdClass|null
     */
    public ?stdClass $candidate = null;

    /**
     * The lookups made, as "provider:installationId:repositoryOwner".
     *
     * @var string[]
     */
    public array $lookups = [];

    /**
     * The user ids whose key was recorded as used.
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
        $this->lookups[] = "{$this->provider}:{$installationId}:{$repositoryOwner}";
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
     * @param string $encryptedToken The stored value that failed.
     * @param string $error          Why the key can't be used.
     *
     * @return void
     */
    protected function recordError(int $userId, string $encryptedToken, string $error): void
    {
        $this->errors[$userId] = $error;

    }//end recordError()


}//end class


/**
 * Tests reading a provider's API key (e.g. AppVeyor) from the stored integrations.
 */
class IntegrationKeyResolverTest extends TestCase
{
    /**
     * The provider looked up by the tests.
     */
    private const PROVIDER = "AppVeyor";

    /**
     * The LogStream category given to the resolver.
     */
    private const LOG_CATEGORY = "appveyor";

    /**
     * Encrypts the stored keys of the tests.
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
     * Builds a stored candidate whose key was encrypted with the given helper.
     *
     * @param CryptoHelper $crypto The helper that encrypted the stored key.
     *
     * @return stdClass The candidate.
     */
    private function createCandidate(CryptoHelper $crypto): stdClass
    {
        $candidate = new stdClass();
        $candidate->userId = InMemoryIntegrationKeyResolver::USER_ID;
        $candidate->login = "octocat";
        $candidate->encryptedToken = $crypto->encrypt("appveyor-key");
        return $candidate;

    }//end createCandidate()


    /**
     * The stored key of the provider is decrypted and its use recorded.
     *
     * @return void
     */
    public function testFindsStoredKey(): void
    {
        $resolver = new InMemoryIntegrationKeyResolver(self::PROVIDER, $this->crypto, self::LOG_CATEGORY);
        $resolver->candidate = $this->createCandidate($this->crypto);

        $integration = $resolver->find(123, "octo-org");

        $this->assertNotNull($integration);
        $this->assertSame("appveyor-key", $integration->key);
        $this->assertSame("octocat", $integration->login);
        $this->assertSame(InMemoryIntegrationKeyResolver::USER_ID, $integration->userId);
        $this->assertSame(["AppVeyor:123:octo-org"], $resolver->lookups);
        $this->assertSame([InMemoryIntegrationKeyResolver::USER_ID], $resolver->used);

    }//end testFindsStoredKey()


    /**
     * Without a stored integration there is no key.
     *
     * @return void
     */
    public function testReturnsNullWithoutIntegration(): void
    {
        $resolver = new InMemoryIntegrationKeyResolver(self::PROVIDER, $this->crypto, self::LOG_CATEGORY);

        $this->assertNull($resolver->find(123, "octo-org"));
        $this->assertSame([], $resolver->used);

    }//end testReturnsNullWithoutIntegration()


    /**
     * A key that can't be decrypted is not used and is marked as failed.
     *
     * @return void
     */
    public function testRecordsUndecryptableKey(): void
    {
        $resolver = new InMemoryIntegrationKeyResolver(self::PROVIDER, $this->crypto, self::LOG_CATEGORY);
        $resolver->candidate = $this->createCandidate(new CryptoHelper(base64_encode(str_repeat("x", 32))));

        $this->assertNull($resolver->find(123, "octo-org"));
        $this->assertArrayHasKey(InMemoryIntegrationKeyResolver::USER_ID, $resolver->errors);
        $this->assertSame([], $resolver->used);

    }//end testRecordsUndecryptableKey()


    /**
     * A database failure is treated as no key stored.
     *
     * @return void
     */
    public function testReturnsNullWhenDatabaseFails(): void
    {
        $resolver = new InMemoryIntegrationKeyResolver(self::PROVIDER, $this->crypto, self::LOG_CATEGORY);
        $resolver->databaseDown = true;

        $this->assertNull($resolver->find(123, "octo-org"));

    }//end testReturnsNullWhenDatabaseFails()


}//end class
