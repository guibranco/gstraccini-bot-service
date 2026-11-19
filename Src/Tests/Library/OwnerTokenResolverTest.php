<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\CryptoHelper;
use GuiBranco\GStracciniBot\Library\OwnerCredentials;
use GuiBranco\GStracciniBot\Library\OwnerTokenResolver;
use PHPUnit\Framework\TestCase;

/**
 * Resolver whose database access is replaced by an in-memory candidate.
 */
class InMemoryOwnerTokenResolver extends OwnerTokenResolver
{
    /** @var array{userId: int, login: string, encryptedToken: string}|null */
    public ?array $candidate = null;

    /** @var array<int, array{installationId: int, repositoryOwner: string}> */
    public array $lookups = [];

    /** @var int[] */
    public array $used = [];

    /** @var array<int, string> */
    public array $errors = [];

    protected function findCandidate(int $installationId, string $repositoryOwner): ?array
    {
        $this->lookups[] = ["installationId" => $installationId, "repositoryOwner" => $repositoryOwner];
        return $this->candidate;
    }

    protected function recordUsage(int $userId): void
    {
        $this->used[] = $userId;
    }

    protected function recordError(int $userId, string $error): void
    {
        $this->errors[$userId] = $error;
    }
}

class OwnerTokenResolverTest extends TestCase
{
    private CryptoHelper $crypto;

    protected function setUp(): void
    {
        $this->crypto = new CryptoHelper(base64_encode(str_repeat("k", 32)));
    }

    public function testUsesTheStoredIntegrationOfTheInstallation(): void
    {
        $resolver = new InMemoryOwnerTokenResolver($this->crypto, "fallback-token", "fallback-login");
        $resolver->candidate = ["userId" => 42, "login" => "octocat", "encryptedToken" => $this->crypto->encrypt("stored-token")];

        $owner = $resolver->resolve(123, "octo-org");

        $this->assertNotNull($owner);
        $this->assertSame("stored-token", $owner->token);
        $this->assertSame("octocat", $owner->login);
        $this->assertSame(OwnerCredentials::SOURCE_DATABASE, $owner->source);
        $this->assertSame([["installationId" => 123, "repositoryOwner" => "octo-org"]], $resolver->lookups);
        $this->assertSame([42], $resolver->used);
    }

    public function testFallsBackToTheServiceTokenWhenNoIntegrationIsStored(): void
    {
        $resolver = new InMemoryOwnerTokenResolver($this->crypto, "fallback-token", "fallback-login");

        $owner = $resolver->resolve(123, "octo-org");

        $this->assertNotNull($owner);
        $this->assertSame("fallback-token", $owner->token);
        $this->assertSame("fallback-login", $owner->login);
        $this->assertSame(OwnerCredentials::SOURCE_FALLBACK, $owner->source);
    }

    public function testFallsBackAndRecordsTheErrorWhenTheStoredTokenCannotBeDecrypted(): void
    {
        $resolver = new InMemoryOwnerTokenResolver($this->crypto, "fallback-token", "fallback-login");
        $other = new CryptoHelper(base64_encode(str_repeat("x", 32)));
        $resolver->candidate = ["userId" => 42, "login" => "octocat", "encryptedToken" => $other->encrypt("stored-token")];

        $owner = $resolver->resolve(123, "octo-org");

        $this->assertSame(OwnerCredentials::SOURCE_FALLBACK, $owner?->source);
        $this->assertArrayHasKey(42, $resolver->errors);
        $this->assertSame([], $resolver->used);
    }

    public function testReturnsNullWhenNeitherSourceIsAvailable(): void
    {
        $resolver = new InMemoryOwnerTokenResolver($this->crypto, "", "fallback-login");

        $this->assertNull($resolver->resolve(123, "octo-org"));
    }
}
