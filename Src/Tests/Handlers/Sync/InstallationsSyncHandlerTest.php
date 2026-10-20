<?php

namespace GuiBranco\GStracciniBot\Tests\Handlers\Sync;

use DateTimeImmutable;
use DateTimeZone;
use GuiBranco\GStracciniBot\Handlers\Sync\InstallationsSyncHandler;
use PHPUnit\Framework\TestCase;

class InstallationsSyncHandlerTest extends TestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-10-20 10:26:00", new DateTimeZone("Europe/Dublin"));
    }

    public function testRepositoriesSyncIsDueWhenNeverSynced(): void
    {
        $this->assertTrue(InstallationsSyncHandler::isRepositoriesSyncDue(null, $this->now()));
        $this->assertTrue(InstallationsSyncHandler::isRepositoriesSyncDue("", $this->now()));
    }

    public function testRepositoriesSyncIsDueWhenLastSyncIsThreeMonthsOrOlder(): void
    {
        $this->assertTrue(InstallationsSyncHandler::isRepositoriesSyncDue("2026-07-20 10:26:00", $this->now()));
        $this->assertTrue(InstallationsSyncHandler::isRepositoriesSyncDue("2026-01-01 00:00:00", $this->now()));
    }

    public function testRepositoriesSyncIsNotDueWhenLastSyncIsMoreRecent(): void
    {
        $this->assertFalse(InstallationsSyncHandler::isRepositoriesSyncDue("2026-07-20 10:26:01", $this->now()));
        $this->assertFalse(InstallationsSyncHandler::isRepositoriesSyncDue("2026-10-01 00:00:00", $this->now()));
    }

    public function testTheResyncWindowCanBeChanged(): void
    {
        $this->assertTrue(InstallationsSyncHandler::isRepositoriesSyncDue("2026-09-20 10:26:00", $this->now(), 1));
        $this->assertFalse(InstallationsSyncHandler::isRepositoriesSyncDue("2026-09-20 10:26:00", $this->now(), 2));
    }

    public function testDefaultResyncWindowIsThreeMonths(): void
    {
        $this->assertSame(3, InstallationsSyncHandler::REPOSITORIES_RESYNC_MONTHS);
    }

    public function testInstallationChangesIsEmptyWhenTheRowMatchesGitHub(): void
    {
        $existing = (object) [
            "AccountLogin" => "guibranco",
            "AccountType" => "User",
            "AccountNodeId" => "MDQ6VXNlcjMzNjI4NTQ=",
            "RepositorySelection" => "all",
        ];
        $installation = (object) [
            "account" => (object) ["login" => "guibranco", "type" => "User", "node_id" => "MDQ6VXNlcjMzNjI4NTQ="],
            "repository_selection" => "all",
        ];

        $this->assertSame([], InstallationsSyncHandler::installationChanges($existing, $installation));
    }

    public function testInstallationChangesListsTheColumnsThatDiffer(): void
    {
        $existing = (object) [
            "AccountLogin" => "old-login",
            "AccountType" => "User",
            "AccountNodeId" => "MDQ6VXNlcjMzNjI4NTQ=",
            "RepositorySelection" => "all",
        ];
        $installation = (object) [
            "account" => (object) ["login" => "new-login", "type" => "User", "node_id" => "MDQ6VXNlcjMzNjI4NTQ="],
            "repository_selection" => "selected",
        ];

        $this->assertSame(
            ["AccountLogin", "RepositorySelection"],
            InstallationsSyncHandler::installationChanges($existing, $installation)
        );
    }
}
