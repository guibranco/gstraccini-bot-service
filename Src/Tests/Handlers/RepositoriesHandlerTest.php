<?php

namespace GuiBranco\GStracciniBot\Handlers;

use GuiBranco\GStracciniBot\Tests\Handlers\RepositoriesHandlerTest;
use GuiBranco\Pancake\Response;

// Test-only override of the global requestInstallationAccessToken() used by the
// Handlers namespace (see PullRequestsHandlerTest for the same trick with
// doRequestGitHub()). PHP falls back to the global function only when no
// same-namespaced one exists, so this shadows the real (network-calling)
// implementation from Src/lib/github.php for every call made from within
// GuiBranco\GStracciniBot\Handlers during these tests.
if (function_exists(__NAMESPACE__ . '\requestInstallationAccessToken') === false) {
    /**
     * @param string     $installationId The installation the token is requested for.
     * @param array|null $repositoryIds  Repository ids the token should be scoped to.
     * @param array|null $permissions    Permissions requested for the token.
     *
     * @return Response The response the running test configured.
     */
    function requestInstallationAccessToken(string $installationId, ?array $repositoryIds = null, ?array $permissions = null): Response
    {
        return RepositoriesHandlerTest::handleFakeTokenRequest($installationId, $repositoryIds, $permissions);
    }
}

namespace GuiBranco\GStracciniBot\Tests\Handlers;

use GuiBranco\GStracciniBot\Handlers\RepositoriesHandler;
use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\Pancake\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RepositoriesHandlerTest extends TestCase
{
    private const INSTALLATION_ID = 4242;

    private const REPOSITORY_ID = 123456;

    private const TOKENS_URL = "app/installations/4242/access_tokens";

    /** @var Response|null The response the fake token endpoint answers with (an error when unset). */
    private static ?Response $tokenResponse = null;

    /** @var array<int, array{installationId: string, repositoryIds: ?array, permissions: ?array}> */
    private static array $tokenRequests = [];

    protected function setUp(): void
    {
        self::$tokenResponse = null;
        self::$tokenRequests = [];
    }

    /**
     * The fake `POST /app/installations/{id}/access_tokens`: records the request
     * and answers with whatever the running test configured.
     *
     * @param string     $installationId The installation the token is requested for.
     * @param array|null $repositoryIds  Repository ids the token should be scoped to.
     * @param array|null $permissions    Permissions requested for the token.
     *
     * @return Response The configured response.
     */
    public static function handleFakeTokenRequest(string $installationId, ?array $repositoryIds, ?array $permissions): Response
    {
        self::$tokenRequests[] = [
            "installationId" => $installationId,
            "repositoryIds" => $repositoryIds,
            "permissions" => $permissions,
        ];

        return self::$tokenResponse ?? Response::error("No fake token response configured", self::TOKENS_URL, -1);
    }

    /**
     * The token request statuses that mean the repository is gone, with the
     * reason the handler reports for each.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function goneTokenStatuses(): array
    {
        return [
            "404: the installation no longer exists" => [404, "Installation #4242 no longer exists"],
            "422: the repository is not accessible to the installation" => [422, "No longer accessible to installation #4242"],
        ];
    }

    public function testIsRemovedForDeletedAndRemovedRows(): void
    {
        $handler = new RepositoriesHandler($this->createMock(CheckupRepository::class));

        $this->assertFalse($handler->isRemoved($this->buildRow()));
        $this->assertFalse($handler->isRemoved($this->buildRow(["LastAction" => "edited", "InstallationAction" => null])));

        // The receiver's write for the `deleted` event.
        $this->assertTrue($handler->isRemoved($this->buildRow(["LastAction" => "deleted", "InstallationAction" => "added"])));
        // CheckupRepository::markRepositoryRemoved()'s appended row.
        $this->assertTrue($handler->isRemoved($this->buildRow(["LastAction" => "deleted", "InstallationAction" => "removed"])));
        $this->assertTrue($handler->isRemoved($this->buildRow(["InstallationAction" => "removed"])));
    }

    public function testRemovedRowSkipsTheTokenRequest(): void
    {
        $repository = $this->createMock(CheckupRepository::class);
        $repository->expects($this->never())->method("markRepositoryRemoved");
        $repository->expects($this->never())->method("isRepositoryActiveForInstallation");

        $output = $this->runHandler($repository, $this->buildRow(["LastAction" => "deleted", "InstallationAction" => "removed"]));

        $this->assertSame([], self::$tokenRequests, "A removed repository must not reach GitHub");
        $this->assertStringContainsString("nothing to set up", $output);
    }

    #[DataProvider("goneTokenStatuses")]
    public function testGoneRepositoryIsMarkedRemoved(int $status, string $reason): void
    {
        self::$tokenResponse = $this->gitHubError($status);

        $repository = $this->createMock(CheckupRepository::class);
        $repository->method("isRepositoryActiveForInstallation")->with(self::REPOSITORY_ID, self::INSTALLATION_ID)->willReturn(true);
        $repository->expects($this->once())
            ->method("markRepositoryRemoved")
            ->with(self::REPOSITORY_ID, self::INSTALLATION_ID, $this->stringStartsWith("repositories_handler:"));

        $output = $this->runHandler($repository, $this->buildRow());

        $this->assertStringContainsString($reason, $output);
        $this->assertStringContainsString("marking repository removed", $output);
        // The token is scoped by repository id (rename/transfer safe), never by name.
        $this->assertCount(1, self::$tokenRequests);
        $this->assertSame((string) self::INSTALLATION_ID, self::$tokenRequests[0]["installationId"]);
        $this->assertSame([self::REPOSITORY_ID], self::$tokenRequests[0]["repositoryIds"]);
        $this->assertNull(self::$tokenRequests[0]["permissions"]);
    }

    /**
     * A stale row — the repository's latest row already records the removal
     * (a sync got there first) or belongs to another installation (it was
     * transferred or re-registered since this row was queued) — is finalized
     * without writing a removed row, which would retire the live repository.
     */
    public function testStaleRowDoesNotRecordTheRemoval(): void
    {
        self::$tokenResponse = $this->gitHubError(422);

        $repository = $this->createMock(CheckupRepository::class);
        $repository->method("isRepositoryActiveForInstallation")->with(self::REPOSITORY_ID, self::INSTALLATION_ID)->willReturn(false);
        $repository->expects($this->never())->method("markRepositoryRemoved");

        $output = $this->runHandler($repository, $this->buildRow());

        $this->assertStringContainsString("belongs to another installation, leaving it as is", $output);
        $this->assertStringContainsString("nothing to set up", $output);
    }

    public function testOtherTokenFailuresStillFailTheItem(): void
    {
        self::$tokenResponse = $this->gitHubError(403, "This installation has been suspended");

        $repository = $this->createMock(CheckupRepository::class);
        $repository->expects($this->never())->method("markRepositoryRemoved");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("HTTP status 403");

        $this->runHandler($repository, $this->buildRow());
    }

    /**
     * Runs the handler on a row with its console output captured.
     *
     * @param CheckupRepository $repository The (mocked) `github_repositories` access to hand the handler.
     * @param object            $row        The queued row.
     *
     * @return string Everything the handler echoed.
     */
    private function runHandler(CheckupRepository $repository, object $row): string
    {
        $handler = new RepositoriesHandler($repository);

        ob_start();
        try {
            $handler->handleItem($row);
        } finally {
            $output = ob_get_clean();
        }

        return $output;
    }

    /**
     * A `github_repositories` row as the worker hands it to the handler, for an
     * active repository unless overridden.
     *
     * @param array<string, mixed> $overrides Column values to replace.
     *
     * @return object The row.
     */
    private function buildRow(array $overrides = []): object
    {
        $row = [
            "Sequence" => 99,
            "DeliveryIdText" => "0f8fad5b-d9cb-469f-a165-70867728950e",
            "Id" => self::REPOSITORY_ID,
            "Name" => "repo",
            "FullName" => "owner/repo",
            "OwnerLogin" => "owner",
            "InstallationId" => self::INSTALLATION_ID,
            "LastAction" => "created",
            "InstallationAction" => "added",
        ];

        return (object) array_merge($row, $overrides);
    }

    /**
     * A GitHub error response with the JSON body the API sends.
     *
     * @param int    $status  The HTTP status.
     * @param string $message GitHub's `message` property.
     *
     * @return Response The response.
     */
    private function gitHubError(int $status, string $message = "Not Found"): Response
    {
        $body = json_encode(["message" => $message, "status" => (string) $status]);

        return Response::success($body, self::TOKENS_URL, [], $status);
    }
}
