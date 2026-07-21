<?php

namespace GuiBranco\GStracciniBot\Library\Checkup;

use GuiBranco\Pancake\GUIDv4;

/**
 * Existence checks and insert-if-missing writes for the reconciliation
 * ("checkup") job, against the shared tables owned by the `webhooks`
 * project (github_installations, github_repositories, github_branches,
 * github_pull_requests, github_issues, github_pushes).
 *
 * Every insert*() method only ever INSERTs new rows with
 * ProcessingState = 'NEW' — it never updates or resets existing rows, so
 * already-registered entities are left untouched (unlike the webhook
 * receiver's save*() functions, which also handle updates).
 */
class CheckupRepository
{
    private const TARGET_TYPE = "integration";

    public function existsInstallation(int $installationId): bool
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("SELECT 1 FROM github_installations WHERE InstallationId = ? LIMIT 1");
        $stmt->bind_param("i", $installationId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        $mysqli->close();
        return $exists;
    }

    public function insertInstallation(object $installation, string $webhooksHandlerVersion): void
    {
        $fields = [
            "DeliveryId", "HookId", "TargetId", "TargetType",
            "InstallationId", "AccountId", "AccountLogin", "AccountType", "AccountNodeId",
            "RepositorySelection", "SenderLogin", "SenderId", "SenderNodeId",
            "WebhooksHandlerVersion"
        ];

        $mysqli = connectToDatabase();
        $sql = "INSERT INTO github_installations (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);

        $deliveryId = GUIDv4::random();
        $hookId = 0;
        $targetId = $installation->id;
        $targetType = self::TARGET_TYPE;
        $installationId = $installation->id;
        $accountId = $installation->account->id;
        $accountLogin = $installation->account->login;
        $accountType = $installation->account->type;
        $accountNodeId = $installation->account->node_id;
        $repositorySelection = $installation->repository_selection;
        $senderLogin = $installation->account->login;
        $senderId = $installation->account->id;
        $senderNodeId = $installation->account->node_id;

        $stmt->bind_param(
            'siisiisssssiss',
            $deliveryId,
            $hookId,
            $targetId,
            $targetType,
            $installationId,
            $accountId,
            $accountLogin,
            $accountType,
            $accountNodeId,
            $repositorySelection,
            $senderLogin,
            $senderId,
            $senderNodeId,
            $webhooksHandlerVersion
        );
        $stmt->execute();
        $stmt->close();
        $mysqli->close();
    }

    public function existsRepository(int $repositoryId): bool
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare(
            "SELECT 1 FROM github_repositories WHERE Id = ? AND LastAction != 'deleted' ORDER BY Sequence DESC LIMIT 1"
        );
        $stmt->bind_param("i", $repositoryId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        $mysqli->close();
        return $exists;
    }

    public function insertRepository(object $repository, int $installationId, string $webhooksHandlerVersion): void
    {
        $fields = [
            "DeliveryId", "HookId", "TargetId", "TargetType",
            "Id", "NodeId", "Name", "FullName", "Private",
            "OwnerLogin", "OwnerId", "OwnerNodeId",
            "SenderLogin", "SenderId", "SenderNodeId",
            "InstallationId", "WebhooksHandlerVersion",
            "InstallationAction", "InstallationActionDate"
        ];

        $mysqli = connectToDatabase();
        $sql = "INSERT INTO github_repositories (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'added', NOW())";
        $stmt = $mysqli->prepare($sql);

        $deliveryId = GUIDv4::random();
        $hookId = 0;
        $targetId = $installationId;
        $targetType = self::TARGET_TYPE;
        $id = $repository->id;
        $nodeId = $repository->node_id;
        $name = $repository->name;
        $fullName = $repository->full_name;
        $private = $repository->private ? 1 : 0;
        $ownerLogin = $repository->owner->login;
        $ownerId = $repository->owner->id;
        $ownerNodeId = $repository->owner->node_id;
        $senderLogin = $repository->owner->login;
        $senderId = $repository->owner->id;
        $senderNodeId = $repository->owner->node_id;

        $stmt->bind_param(
            'siisisssisissisis',
            $deliveryId,
            $hookId,
            $targetId,
            $targetType,
            $id,
            $nodeId,
            $name,
            $fullName,
            $private,
            $ownerLogin,
            $ownerId,
            $ownerNodeId,
            $senderLogin,
            $senderId,
            $senderNodeId,
            $installationId,
            $webhooksHandlerVersion
        );
        $stmt->execute();
        $stmt->close();
        $mysqli->close();
    }

    /**
     * Returns the Id of every repository currently considered active
     * (latest row's LastAction != 'deleted') for the given installation.
     *
     * @return array<int>
     */
    public function getActiveRepositoryIdsForInstallation(int $installationId): array
    {
        $mysqli = connectToDatabase();
        $sql = "SELECT r.Id FROM github_repositories r
                INNER JOIN (
                    SELECT Id, MAX(Sequence) AS MaxSequence
                    FROM github_repositories
                    WHERE InstallationId = ?
                    GROUP BY Id
                ) latest ON r.Id = latest.Id AND r.Sequence = latest.MaxSequence
                WHERE r.LastAction != 'deleted'";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $installationId);
        $stmt->execute();
        $result = $stmt->get_result();

        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['Id'];
        }

        $stmt->close();
        $mysqli->close();
        return $ids;
    }

    /**
     * Records that a repository is no longer accessible to an installation
     * by inserting a new `github_repositories` row (LastAction = 'deleted',
     * InstallationAction = 'removed'), copying the descriptive fields from
     * the repository's most recent row. This is an append, never a DELETE,
     * consistent with the rest of this event-log table.
     */
    public function markRepositoryRemoved(int $repositoryId, int $installationId, string $webhooksHandlerVersion): void
    {
        $mysqli = connectToDatabase();

        $stmt = $mysqli->prepare(
            "SELECT NodeId, Name, FullName, Private, OwnerLogin, OwnerId, OwnerNodeId
             FROM github_repositories WHERE Id = ? ORDER BY Sequence DESC LIMIT 1"
        );
        $stmt->bind_param("i", $repositoryId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            $mysqli->close();
            return;
        }

        $fields = [
            "DeliveryId", "HookId", "TargetId", "TargetType",
            "Id", "NodeId", "Name", "FullName", "Private",
            "OwnerLogin", "OwnerId", "OwnerNodeId",
            "SenderLogin", "SenderId", "SenderNodeId",
            "InstallationId", "WebhooksHandlerVersion"
        ];

        $sql = "INSERT INTO github_repositories (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'deleted', NOW(), 'removed', NOW())";
        $stmt = $mysqli->prepare($sql);

        $deliveryId = GUIDv4::random();
        $hookId = 0;
        $targetId = $installationId;
        $targetType = self::TARGET_TYPE;
        $nodeId = $row['NodeId'];
        $name = $row['Name'];
        $fullName = $row['FullName'];
        $private = (int) $row['Private'];
        $ownerLogin = $row['OwnerLogin'];
        $ownerId = (int) $row['OwnerId'];
        $ownerNodeId = $row['OwnerNodeId'];
        $senderLogin = $ownerLogin;
        $senderId = $ownerId;
        $senderNodeId = $ownerNodeId;

        $stmt->bind_param(
            'siisisssisissisis',
            $deliveryId,
            $hookId,
            $targetId,
            $targetType,
            $repositoryId,
            $nodeId,
            $name,
            $fullName,
            $private,
            $ownerLogin,
            $ownerId,
            $ownerNodeId,
            $senderLogin,
            $senderId,
            $senderNodeId,
            $installationId,
            $webhooksHandlerVersion
        );
        $stmt->execute();
        $stmt->close();
        $mysqli->close();
    }

    public function existsBranch(string $owner, string $repo, string $ref): bool
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare(
            "SELECT 1 FROM github_branches_view WHERE RepositoryOwner = ? AND RepositoryName = ? AND Ref = ? LIMIT 1"
        );
        $stmt->bind_param("sss", $owner, $repo, $ref);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        $mysqli->close();
        return $exists;
    }

    public function insertBranch(
        string $owner,
        string $repo,
        string $ref,
        string $masterBranch,
        int $installationId,
        object $repositoryOwner,
        string $webhooksHandlerVersion
    ): void {
        $fields = [
            "DeliveryId", "HookId", "TargetId", "TargetType", "InstallationId",
            "RepositoryOwner", "RepositoryName", "Event", "Ref", "RefType", "MasterBranch",
            "SenderLogin", "SenderId", "SenderNodeId", "WebhooksHandlerVersion"
        ];

        $mysqli = connectToDatabase();
        $sql = "INSERT INTO github_branches (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, 'create', ?, 'branch', ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);

        $deliveryId = GUIDv4::random();
        $hookId = 0;
        $targetId = $installationId;
        $targetType = self::TARGET_TYPE;
        $senderLogin = $repositoryOwner->login;
        $senderId = $repositoryOwner->id;
        $senderNodeId = $repositoryOwner->node_id;

        $stmt->bind_param(
            'siisisssssiss',
            $deliveryId,
            $hookId,
            $targetId,
            $targetType,
            $installationId,
            $owner,
            $repo,
            $ref,
            $masterBranch,
            $senderLogin,
            $senderId,
            $senderNodeId,
            $webhooksHandlerVersion
        );
        $stmt->execute();
        $stmt->close();
        $mysqli->close();
    }

    public function existsPullRequest(string $owner, string $repo, int $number): bool
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare(
            "SELECT 1 FROM github_pull_requests WHERE RepositoryOwner = ? AND RepositoryName = ? AND Number = ? LIMIT 1"
        );
        $stmt->bind_param("ssi", $owner, $repo, $number);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        $mysqli->close();
        return $exists;
    }

    public function insertPullRequest(object $pullRequest, string $owner, string $repo, int $installationId, string $webhooksHandlerVersion): void
    {
        $fields = [
            "DeliveryId", "HookId", "TargetId", "TargetType",
            "RepositoryOwner", "RepositoryName", "Id", "Number", "Sender", "NodeId",
            "Title", "Ref", "InstallationId", "State", "WebhooksHandlerVersion"
        ];

        $mysqli = connectToDatabase();
        $sql = "INSERT INTO github_pull_requests (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'OPEN', ?)";
        $stmt = $mysqli->prepare($sql);

        $deliveryId = GUIDv4::random();
        $hookId = 0;
        $targetId = $installationId;
        $targetType = self::TARGET_TYPE;
        $id = $pullRequest->id;
        $number = $pullRequest->number;
        $sender = $pullRequest->user->login;
        $nodeId = $pullRequest->node_id;
        $title = $pullRequest->title;
        $ref = $pullRequest->head->ref;

        $stmt->bind_param(
            'siisssiissssis',
            $deliveryId,
            $hookId,
            $targetId,
            $targetType,
            $owner,
            $repo,
            $id,
            $number,
            $sender,
            $nodeId,
            $title,
            $ref,
            $installationId,
            $webhooksHandlerVersion
        );
        $stmt->execute();
        $stmt->close();
        $mysqli->close();
    }

    public function existsIssue(string $owner, string $repo, int $number): bool
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare(
            "SELECT 1 FROM github_issues WHERE RepositoryOwner = ? AND RepositoryName = ? AND Number = ? LIMIT 1"
        );
        $stmt->bind_param("ssi", $owner, $repo, $number);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        $mysqli->close();
        return $exists;
    }

    public function insertIssue(object $issue, string $owner, string $repo, int $installationId, string $webhooksHandlerVersion): void
    {
        $fields = [
            "DeliveryId", "HookId", "TargetId", "TargetType",
            "RepositoryOwner", "RepositoryName", "Id", "Number", "NodeId", "Title", "Sender",
            "InstallationId", "State", "WebhooksHandlerVersion"
        ];

        $mysqli = connectToDatabase();
        $sql = "INSERT INTO github_issues (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'OPEN', ?)";
        $stmt = $mysqli->prepare($sql);

        $deliveryId = GUIDv4::random();
        $hookId = 0;
        $targetId = $installationId;
        $targetType = self::TARGET_TYPE;
        $id = $issue->id;
        $number = $issue->number;
        $nodeId = $issue->node_id;
        $title = $issue->title;
        $sender = $issue->user->login;

        $stmt->bind_param(
            'siisssiisssis',
            $deliveryId,
            $hookId,
            $targetId,
            $targetType,
            $owner,
            $repo,
            $id,
            $number,
            $nodeId,
            $title,
            $sender,
            $installationId,
            $webhooksHandlerVersion
        );
        $stmt->execute();
        $stmt->close();
        $mysqli->close();
    }

    public function existsPush(string $headCommitId): bool
    {
        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare("SELECT 1 FROM github_pushes WHERE HeadCommitId = ? LIMIT 1");
        $stmt->bind_param("s", $headCommitId);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        $mysqli->close();
        return $exists;
    }

    public function insertPush(object $commit, string $owner, string $repo, string $ref, int $installationId, string $webhooksHandlerVersion): void
    {
        $fields = [
            "DeliveryId", "HookId", "TargetId", "TargetType",
            "RepositoryOwner", "RepositoryName", "Ref",
            "HeadCommitId", "HeadCommitTreeId", "HeadCommitMessage", "HeadCommitTimestamp",
            "HeadCommitAuthorName", "HeadCommitAuthorEmail",
            "HeadCommitCommitterName", "HeadCommitCommitterEmail",
            "InstallationId", "WebhooksHandlerVersion"
        ];

        $mysqli = connectToDatabase();
        $sql = "INSERT INTO github_pushes (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);

        $deliveryId = GUIDv4::random();
        $hookId = 0;
        $targetId = $installationId;
        $targetType = self::TARGET_TYPE;
        $headCommitId = $commit->sha;
        $headCommitTreeId = $commit->commit->tree->sha;
        $headCommitMessage = substr($commit->commit->message, 0, 65535);
        $headCommitTimestamp = date("Y-m-d H:i:s", strtotime($commit->commit->author->date));
        $headCommitAuthorName = $commit->commit->author->name;
        $headCommitAuthorEmail = $commit->commit->author->email;
        $headCommitCommitterName = $commit->commit->committer->name;
        $headCommitCommitterEmail = $commit->commit->committer->email;

        $stmt->bind_param(
            'siissssssssssssis',
            $deliveryId,
            $hookId,
            $targetId,
            $targetType,
            $owner,
            $repo,
            $ref,
            $headCommitId,
            $headCommitTreeId,
            $headCommitMessage,
            $headCommitTimestamp,
            $headCommitAuthorName,
            $headCommitAuthorEmail,
            $headCommitCommitterName,
            $headCommitCommitterEmail,
            $installationId,
            $webhooksHandlerVersion
        );
        $stmt->execute();
        $stmt->close();
        $mysqli->close();
    }
}
