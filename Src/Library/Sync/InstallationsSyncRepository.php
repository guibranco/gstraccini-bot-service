<?php

namespace GuiBranco\GStracciniBot\Library\Sync;

use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use RuntimeException;

/**
 * `github_installations` access for the monthly installations sync, against
 * the shared table owned by the `webhooks` project.
 *
 * The table holds one row per event that announced the installation, so it
 * has no unique key on InstallationId. Every read here works on the **most
 * recent row per InstallationId** (highest Sequence) and every write targets
 * that row by Sequence. Inserts are delegated to CheckupRepository so the
 * sync writes exactly the row the checkup job does.
 *
 * LastSyncDate was added by webhooks migration Sql/0041; see its ADR-0011
 * for what the date means.
 */
class InstallationsSyncRepository extends SyncRepositoryBase
{
    private CheckupRepository $checkupRepository;

    public function __construct(?CheckupRepository $checkupRepository = null)
    {
        $this->checkupRepository = $checkupRepository ?? new CheckupRepository();
    }

    /**
     * The most recent `github_installations` row of every installation ever
     * registered, keyed by InstallationId.
     *
     * @return array<int, object>
     */
    public function getLatestInstallations(): array
    {
        $mysqli = connectToDatabase();
        $sql = "SELECT i.Sequence, i.InstallationId, i.AccountId, i.AccountLogin, i.AccountType, i.AccountNodeId,
                       i.RepositorySelection, i.Status, i.LastSyncDate
                FROM github_installations i
                INNER JOIN (
                    SELECT InstallationId, MAX(Sequence) AS MaxSequence
                    FROM github_installations
                    GROUP BY InstallationId
                ) latest ON latest.InstallationId = i.InstallationId AND latest.MaxSequence = i.Sequence
                ORDER BY i.InstallationId ASC";

        $result = $this->queryOrFail($mysqli, $sql);

        $installations = [];
        while ($row = $result->fetch_object()) {
            $installations[(int) $row->InstallationId] = $row;
        }

        $result->close();
        $mysqli->close();
        return $installations;
    }

    /**
     * Registers an installation listed by GitHub that has no row yet, the
     * same way the checkup job backfills one (ProcessingState NEW, so the
     * installations handler maps it like a delivered event), and returns
     * the new row's Sequence.
     */
    public function insertInstallation(object $installation, string $webhooksHandlerVersion): int
    {
        $this->checkupRepository->insertInstallation($installation, $webhooksHandlerVersion);

        $sequence = $this->getLatestInstallationSequence((int) $installation->id);
        if ($sequence === null) {
            throw new RuntimeException("Installation #{$installation->id} was not found right after inserting it");
        }

        return $sequence;
    }

    public function getLatestInstallationSequence(int $installationId): ?int
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("SELECT Sequence FROM github_installations WHERE InstallationId = ? ORDER BY Sequence DESC LIMIT 1");
        $stmt->bind_param("i", $installationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $mysqli->close();

        return $row === null ? null : (int) $row["Sequence"];
    }

    /**
     * Brings the account columns of an installation row in line with what
     * GitHub reports (the account may have been renamed, or switched between
     * "all" and "selected" repositories). Only writes when something differs.
     *
     * @return bool True when the row was changed.
     */
    public function updateInstallationMetadata(int $sequence, object $installation): bool
    {
        $accountLogin = (string) $installation->account->login;
        $accountType = (string) $installation->account->type;
        $accountNodeId = (string) $installation->account->node_id;
        $repositorySelection = (string) $installation->repository_selection;

        $mysqli = connectToDatabase();
        $sql = "UPDATE github_installations
                SET AccountLogin = ?, AccountType = ?, AccountNodeId = ?, RepositorySelection = ?
                WHERE Sequence = ?
                  AND (AccountLogin != ? OR AccountType != ? OR AccountNodeId != ? OR RepositorySelection != ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(
            "ssssissss",
            $accountLogin,
            $accountType,
            $accountNodeId,
            $repositorySelection,
            $sequence,
            $accountLogin,
            $accountType,
            $accountNodeId,
            $repositorySelection
        );

        return $this->executeAndClose($stmt, $mysqli) > 0;
    }

    /**
     * Records that the installation's repositories were just listed and
     * reconciled. Leaves UpdatedAt alone: that column tracks event-driven
     * changes to the row, and a sync pass isn't one.
     */
    public function markInstallationSynced(int $sequence): void
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("UPDATE github_installations SET LastSyncDate = NOW(), UpdatedAt = UpdatedAt WHERE Sequence = ?");
        $stmt->bind_param("i", $sequence);
        $this->executeAndClose($stmt, $mysqli);
    }

    /**
     * Marks an installation GitHub no longer lists as uninstalled — the same
     * write the webhooks receiver does for an `installation` `deleted` event.
     *
     * @return bool True when the row was changed (it wasn't already '0').
     */
    public function markInstallationRemoved(int $sequence): bool
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("UPDATE github_installations SET Status = '0' WHERE Sequence = ? AND Status != '0'");
        $stmt->bind_param("i", $sequence);

        return $this->executeAndClose($stmt, $mysqli) > 0;
    }

    /**
     * Reverts a `deleted` mark on an installation GitHub still lists.
     *
     * @return bool True when the row was changed (it was '0').
     */
    public function markInstallationActive(int $sequence): bool
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("UPDATE github_installations SET Status = '1' WHERE Sequence = ? AND Status = '0'");
        $stmt->bind_param("i", $sequence);

        return $this->executeAndClose($stmt, $mysqli) > 0;
    }
}
