<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

/**
 * The `github_repositories` columns the sync workers keep in step with
 * GitHub (name, full name, visibility, owner, node id), extracted from a
 * GitHub REST repository object, and the comparison against a stored row
 * that tells the workers whether an UPDATE is needed at all.
 *
 * Pure: no database or HTTP access, so it is unit-tested directly.
 */
final class RepositoryMetadata
{
    /** @var array<int, string> The columns compared and updated, in column order. */
    public const FIELDS = ["NodeId", "Name", "FullName", "Private", "OwnerLogin", "OwnerId", "OwnerNodeId"];

    /**
     * Maps a GitHub REST repository object (as returned by `GET /repositories/{id}`,
     * `GET /repos/{owner}/{repo}` or listed by `GET /installation/repositories`)
     * to the column values the workers store.
     *
     * @return array<string, int|string> Column name => value.
     */
    public static function fromGitHub(object $repository): array
    {
        return [
            "NodeId" => (string) $repository->node_id,
            "Name" => (string) $repository->name,
            "FullName" => (string) $repository->full_name,
            "Private" => !empty($repository->private) ? 1 : 0,
            "OwnerLogin" => (string) $repository->owner->login,
            "OwnerId" => (int) $repository->owner->id,
            "OwnerNodeId" => (string) $repository->owner->node_id,
        ];
    }

    /**
     * Compares a stored `github_repositories` row with the values GitHub
     * reports and returns the columns that differ.
     *
     * @param object|array<string, mixed> $row      The stored row (column name => value).
     * @param array<string, int|string>   $metadata Values from `fromGitHub()`.
     *
     * @return array<string, array{from: mixed, to: mixed}> Changed columns, keyed by column name.
     */
    public static function changes(object|array $row, array $metadata): array
    {
        $row = (array) $row;
        $changes = [];

        foreach (self::FIELDS as $field) {
            $current = $row[$field] ?? null;
            $target = $metadata[$field];

            if (self::normalize($field, $current) !== self::normalize($field, $target)) {
                $changes[$field] = ["from" => $current, "to" => $target];
            }
        }

        return $changes;
    }

    /**
     * Whether the changes move the repository to another owner/name, which
     * means the rows keyed by (RepositoryOwner, RepositoryName) in the other
     * `github_*` tables need to follow.
     *
     * @param array<string, array{from: mixed, to: mixed}> $changes From `changes()`.
     */
    public static function isRenamed(array $changes): bool
    {
        return isset($changes["Name"]) || isset($changes["OwnerLogin"]);
    }

    /**
     * Puts a value in the shape it is compared in, so a `"1"` read from a
     * tinyint column equals the `1` built from GitHub's boolean, and so on.
     */
    private static function normalize(string $field, mixed $value): int|string|null
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            "Private", "OwnerId" => (int) $value,
            default => (string) $value,
        };
    }
}
