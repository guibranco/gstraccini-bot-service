<?php

namespace GuiBranco\GStracciniBot\Handlers;

use GuiBranco\GStracciniBot\Tests\Handlers\RepositoriesHandlerTest;
use GuiBranco\Pancake\Response;

/**
 * Test-only override of the global requestInstallationAccessToken() used by
 * the Handlers namespace (see PullRequestsHandlerTest for the same trick with
 * doRequestGitHub()). PHP falls back to the global function only when no
 * same-namespaced one exists, so this shadows the real (network-calling)
 * implementation from Src/lib/github.php for every call made from within
 * GuiBranco\GStracciniBot\Handlers during these tests.
 */
if (!function_exists(__NAMESPACE__ . '\requestInstallationAccessToken')) {
    function requestInstallationAccessToken(string $installationId, ?array $repositoryIds = null, ?array $permissions = null): Response
    {
        return RepositoriesHandlerTest::handleFakeTokenRequest($installationId, $repositoryIds, $permissions);
    }
}

namespace GuiBranco\GStracciniBot\Tests\Handlers;

use GuiBranco\GStracciniBot\Handlers\RepositoriesHandler;
use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\Pancake\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RepositoriesHandlerTest extends TestCase
{
    private const TOKENS_URL = "app/installations/4242/access_tokens";

    private static ?Response $tokenResponse = null;

    /** @var array<int, array{installationId: string, repositoryIds: ?array, permissions: ?array}> */
    private static array $tokenRequests = [];

    protected function setUp(): void
    {
        self::$tokenResponse = null;
        self::$tokenRequests = [];
    }

    public static function handleFakeTokenRequest(string $installationId, ?array $repositoryIds, ?array $permissions): Response
    {
        self::$tokenRequests[] = [
            "installationId" => $installationId,
            "repositoryIds" => $repositoryIds,
            "permissions" => $permissions,
        ];

        return self::$tokenResponse ?? Response::error("No fake token response configured", self::TOKENS_URL, -1);
    }

    private function buildRow(array $overrides = []): object
    {
        return (object) array_merge([
            "Sequence" => 99,
            "DeliveryIdText" => "0f8fad5b-d9cb-469f-a165-70867728950e",
            "Id" => 123456,
            "Name" => "repo",
            "FullName" => "owner/repo",
            "OwnerLogin" => "owner",
            "InstallationId" => 4242,
            "LastAction" => "created",
            "InstallationAction" => "added",
        ], $overrides);
    }

    private function gitHubError(int $status, string $message): Response
    {
        $body = json_encode(["message" => $message, "status" => (string) $status]);

        return Response::success($body, self::TOKENS_URL, [], $status);
    }

    /**
     * @return CheckupRepository&MockObject
     */
    private function checkupRepository(): CheckupRepository
    {
        return $this->createMock(CheckupRepository::class);
    }

    public function testIsRemovedTellsTheRowsThatRecordAGoneRepository(): void
    {
        $this->assertFalse(RepositoriesHandler::isRemoved($this->buildRow()));
        $this->assertFalse(RepositoriesHandler::isRemoved($this->buildRow(["LastAction" => "edited", "InstallationAction" => null])));

        // The receiver's write for the `deleted` event.
        $this->assertTrue(RepositoriesHandler::isRemoved($this->buildRow(["LastAction" => "deleted", "InstallationAction" => "added"])));
        // CheckupRepository::markRepositoryRemoved()'s appended row.
        $this->assertTrue(RepositoriesHandler::isRemoved($this->buildRow(["LastAction" => "deleted", "InstallationAction" => "removed"])));
        $this->assertTrue(RepositoriesHandler::isRemoved($this->buildRow(["InstallationAction" => "removed"])));
    }

    public function testARemovedRowIsFinalizedWithoutAskingGitHubForAToken(): void
    {
        $repository = $this->checkupRepository();
        $repository->expects($this->never())->method("markRepositoryRemoved");
        $repository->expects($this->never())->method("existsRepository");

        $handler = new RepositoriesHandler($repository);

        ob_start();
        try {
            $handler->handleItem($this->buildRow(["LastAction" => "deleted", "InstallationAction" => "removed"]));
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame([], self::$tokenRequests, "A removed repository must not reach GitHub");
        $this->assertStringContainsString("nothing to set up", $output);
    }

    public function testTheTokenIsScopedToTheRepositoryById(): void
    {
        self::$tokenResponse = $this->gitHubError(404, "Not Found");

        $handler = new RepositoriesHandler($this->checkupRepository());

        ob_start();
        try {
            $handler->handleItem($this->buildRow());
        } finally {
            ob_end_clean();
        }

        $this->assertCount(1, self::$tokenRequests);
        $this->assertSame("4242", self::$tokenRequests[0]["installationId"]);
        $this->assertSame([123456], self::$tokenRequests[0]["repositoryIds"]);
        $this->assertNull(self::$tokenRequests[0]["permissions"]);
    }

    public function testAGoneInstallationMarksTheRepositoryRemovedInsteadOfFailing(): void
    {
        self::$tokenResponse = $this->gitHubError(404, "Not Found");

        $repository = $this->checkupRepository();
        $repository->method("existsRepository")->with(123456)->willReturn(true);
        $repository->expects($this->once())
            ->method("markRepositoryRemoved")
            ->with(123456, 4242, $this->stringStartsWith("repositories_handler:"));

        $handler = new RepositoriesHandler($repository);

        ob_start();
        try {
            $handler->handleItem($this->buildRow());
        } finally {
            $output = ob_get_clean();
        }

        $this->assertStringContainsString("Installation #4242 no longer exists", $output);
    }

    public function testARepositoryTheInstallationCannotAccessIsMarkedRemovedInsteadOfFailing(): void
    {
        self::$tokenResponse = $this->gitHubError(422, "There is at least one repository that does not exist or is not accessible to the parent installation.");

        $repository = $this->checkupRepository();
        $repository->method("existsRepository")->with(123456)->willReturn(true);
        $repository->expects($this->once())
            ->method("markRepositoryRemoved")
            ->with(123456, 4242, $this->stringStartsWith("repositories_handler:"));

        $handler = new RepositoriesHandler($repository);

        ob_start();
        try {
            $handler->handleItem($this->buildRow());
        } finally {
            $output = ob_get_clean();
        }

        $this->assertStringContainsString("No longer accessible to installation #4242", $output);
    }

    public function testNoSecondRemovedRowIsAppendedWhenTheRepositoryIsAlreadyRegisteredAsRemoved(): void
    {
        self::$tokenResponse = $this->gitHubError(404, "Not Found");

        $repository = $this->checkupRepository();
        $repository->method("existsRepository")->with(123456)->willReturn(false);
        $repository->expects($this->never())->method("markRepositoryRemoved");

        $handler = new RepositoriesHandler($repository);

        ob_start();
        try {
            $handler->handleItem($this->buildRow());
        } finally {
            ob_end_clean();
        }

        $this->assertCount(1, self::$tokenRequests);
    }

    public function testAnyOtherTokenFailureStillFailsTheItem(): void
    {
        self::$tokenResponse = $this->gitHubError(403, "This installation has been suspended");

        $repository = $this->checkupRepository();
        $repository->expects($this->never())->method("markRepositoryRemoved");

        $handler = new RepositoriesHandler($repository);

        ob_start();
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("HTTP status 403");
            $handler->handleItem($this->buildRow());
        } finally {
            ob_end_clean();
        }
    }
}
