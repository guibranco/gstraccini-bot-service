<?php

namespace GuiBranco\GStracciniBot\Tests\Library\Sync;

use GuiBranco\GStracciniBot\Library\Sync\RepositoryMetadata;
use PHPUnit\Framework\TestCase;

class RepositoryMetadataTest extends TestCase
{
    private function gitHubRepository(array $overrides = []): object
    {
        $repository = [
            "id" => 123456,
            "node_id" => "R_kgDOAbCdEf",
            "name" => "gstraccini-bot-service",
            "full_name" => "guibranco/gstraccini-bot-service",
            "private" => false,
            "owner" => (object) [
                "login" => "guibranco",
                "id" => 3362854,
                "node_id" => "MDQ6VXNlcjMzNjI4NTQ=",
            ],
        ];

        foreach ($overrides as $key => $value) {
            $repository[$key] = $value;
        }

        return (object) $repository;
    }

    private function storedRow(array $overrides = []): array
    {
        return array_merge([
            "Sequence" => 42,
            "Id" => 123456,
            "NodeId" => "R_kgDOAbCdEf",
            "Name" => "gstraccini-bot-service",
            "FullName" => "guibranco/gstraccini-bot-service",
            "Private" => "0",
            "OwnerLogin" => "guibranco",
            "OwnerId" => "3362854",
            "OwnerNodeId" => "MDQ6VXNlcjMzNjI4NTQ=",
        ], $overrides);
    }

    public function testFromGitHubMapsTheStoredColumns(): void
    {
        $metadata = RepositoryMetadata::fromGitHub($this->gitHubRepository(["private" => true]));

        $this->assertSame([
            "NodeId" => "R_kgDOAbCdEf",
            "Name" => "gstraccini-bot-service",
            "FullName" => "guibranco/gstraccini-bot-service",
            "Private" => 1,
            "OwnerLogin" => "guibranco",
            "OwnerId" => 3362854,
            "OwnerNodeId" => "MDQ6VXNlcjMzNjI4NTQ=",
        ], $metadata);
        $this->assertSame(RepositoryMetadata::FIELDS, array_keys($metadata));
    }

    public function testNoChangesWhenTheRowMatchesDespiteDatabaseStringTypes(): void
    {
        $metadata = RepositoryMetadata::fromGitHub($this->gitHubRepository());

        $this->assertSame([], RepositoryMetadata::changes($this->storedRow(), $metadata));
        $this->assertSame([], RepositoryMetadata::changes((object) $this->storedRow(), $metadata));
    }

    public function testDetectsAVisibilityChange(): void
    {
        $metadata = RepositoryMetadata::fromGitHub($this->gitHubRepository(["private" => true]));

        $changes = RepositoryMetadata::changes($this->storedRow(), $metadata);

        $this->assertSame(["Private" => ["from" => "0", "to" => 1]], $changes);
        $this->assertFalse(RepositoryMetadata::isRenamed($changes));
    }

    public function testDetectsARenameAsNameAndFullNameChanges(): void
    {
        $metadata = RepositoryMetadata::fromGitHub($this->gitHubRepository([
            "name" => "gstraccini-bot",
            "full_name" => "guibranco/gstraccini-bot",
        ]));

        $changes = RepositoryMetadata::changes($this->storedRow(), $metadata);

        $this->assertSame(["Name", "FullName"], array_keys($changes));
        $this->assertSame("gstraccini-bot-service", $changes["Name"]["from"]);
        $this->assertSame("gstraccini-bot", $changes["Name"]["to"]);
        $this->assertTrue(RepositoryMetadata::isRenamed($changes));
    }

    public function testDetectsATransferAsOwnerChanges(): void
    {
        $metadata = RepositoryMetadata::fromGitHub($this->gitHubRepository([
            "full_name" => "ApiBR/gstraccini-bot-service",
            "owner" => (object) ["login" => "ApiBR", "id" => 95000000, "node_id" => "O_kgDOBaZt0A"],
        ]));

        $changes = RepositoryMetadata::changes($this->storedRow(), $metadata);

        $this->assertSame(["FullName", "OwnerLogin", "OwnerId", "OwnerNodeId"], array_keys($changes));
        $this->assertTrue(RepositoryMetadata::isRenamed($changes));
    }

    public function testAMissingColumnOnTheRowCountsAsAChange(): void
    {
        $metadata = RepositoryMetadata::fromGitHub($this->gitHubRepository());
        $row = $this->storedRow();
        unset($row["NodeId"]);

        $changes = RepositoryMetadata::changes($row, $metadata);

        $this->assertSame(["NodeId" => ["from" => null, "to" => "R_kgDOAbCdEf"]], $changes);
    }
}
