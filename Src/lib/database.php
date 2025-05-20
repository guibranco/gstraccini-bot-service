<?php

use GuiBranco\Pancake\GUIDv4;

function connectToDatabase($isRetry = false)
{
    global $mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase;

    try {
        $mysqli = new mysqli($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);
        if ($mysqli->connect_errno && $isRetry === false) {
            sleep(10);
            return connectToDatabase(true);
        } elseif ($mysqli->connect_errno) {
            die("Failed to connect to MySQL: " . $mysqli->connect_error);
        }

        $mysqli->set_charset("utf8mb4");

        return $mysqli;
    } catch(Exception $e) {
        if ($isRetry) {
            throw $e;
        }
        sleep(10);
        return connectToDatabase(true);
    }
}

function readTable($tableName, $where = null): ?array
{
    $mysqli = connectToDatabase();
    $defaultWhere = "ProcessingState IN ('NEW', 'RE_REQUESTED', 'UPDATED') ORDER BY Sequence ASC LIMIT 10";
    $sql = "SELECT * FROM " . $tableName . " WHERE ";
    if ($where == null) {
        $sql .= $defaultWhere;
    } else {
        $sql .= $where;
    }
    $result = $mysqli->query($sql);

    if (!$result) {
        return null;
    }

    $data = array();

    while ($obj = $result->fetch_object()) {
        $data[] = $obj;
    }

    $result->close();
    $mysqli->close();

    return $data;
}

function updateTable($tableName, $sequence): bool
{
    $mysqli = connectToDatabase();
    $sql = "UPDATE " . $tableName . " SET ProcessingState = 'PROCESSING', ProcessingDate = NOW() WHERE Sequence = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $sequence);
    $succeeded = false;

    if ($stmt->execute()) {
        $succeeded = $stmt->affected_rows === 1;
    }

    $stmt->close();
    $mysqli->close();
    return $succeeded;
}

function finalizeProcessing($tableName, $sequence): bool
{
    $mysqli = connectToDatabase();
    $sql = "UPDATE " . $tableName . " SET ProcessingState = 'PROCESSED', ProcessedDate = NOW() WHERE Sequence = ? AND ProcessingState = 'PROCESSING' AND ProcessingDate IS NOT NULL";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $sequence);
    $succeeded = false;

    if ($stmt->execute()) {
        $succeeded = $stmt->affected_rows === 1;
    }

    $stmt->close();
    $mysqli->close();
    return $succeeded;
}

function updateStateToClosedInTable($table, $sequence): bool
{
    $mysqli = connectToDatabase();

    $sql = "UPDATE github_{$table} SET State = 'CLOSED'  WHERE Sequence = ? AND State = 'OPEN'";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $sequence);
    $succeeded = false;

    if ($stmt->execute()) {
        $succeeded = $stmt->affected_rows === 1;
    }

    $stmt->close();
    $mysqli->close();
    return $succeeded;
}

function upsertPullRequestMergeable($prUpsert): void
{
    $mysqli = connectToDatabase();
    $sql = "UPDATE github_pull_requests SET Mergeable = ?, MergeableState = ?, Merged = ? WHERE Sequence = ?";
    $stmt = $mysqli->prepare($sql);

    $mergeable = $prUpsert->Mergeable;
    $mergeableState = $prUpsert->MergeableState;
    $merged = $prUpsert->Merged;
    $sequence = $prUpsert->Sequence;
    $stmt->bind_param("isii", $mergeable, $mergeableState, $merged, $sequence);

    if (!$stmt->execute()) {
        $errorMessage = sprintf(
            'Database execute failed: (%d) %s',
            $stmt->errno,
            $stmt->error
        );
        error_log($errorMessage);
        throw new \RuntimeException($errorMessage);
    }
}

function upsertPullRequest($pullRequest)
{
    $mysqli = connectToDatabase();
    $sql = "SELECT Sequence FROM github_pull_requests WHERE NodeId = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $nodeId);

    $nodeId = $pullRequest->NodeId;

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    if ($row) {
        $sql = "UPDATE github_pull_requests SET ProcessingState = 'RE_REQUESTED', ProcessingDate = NULL WHERE Sequence = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $sequence);
        $sequence = $row["Sequence"];

        if (!$stmt->execute()) {
            die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
        }

    } else {
        $fields = array(
            "DeliveryId",
            "HookId",
            "TargetId",
            "TargetType",
            "RepositoryOwner",
            "RepositoryName",
            "Id",
            "Number",
            "Sender",
            "NodeId",
            "Title",
            "Ref",
            "InstallationId"
        );
        $sql = "INSERT INTO github_pull_requests (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(
            'siisssiissssi',
            $deliveryId,
            $hookId,
            $targetId,
            $targetType,
            $repositoryOwner,
            $repositoryName,
            $id,
            $number,
            $sender,
            $nodeId,
            $title,
            $ref,
            $installationId
        );

        $deliveryId = $pullRequest->DeliveryId;
        $hookId = $pullRequest->HookId;
        $targetId = $pullRequest->TargetId;
        $targetType = $pullRequest->TargetType;
        $repositoryOwner = $pullRequest->RepositoryOwner;
        $repositoryName = $pullRequest->RepositoryName;
        $id = $pullRequest->Id;
        $number = $pullRequest->Number;
        $sender = $pullRequest->Sender;
        $nodeId = $pullRequest->NodeId;
        $title = $pullRequest->Title;
        $ref = $pullRequest->Ref;
        $installationId = $pullRequest->InstallationId;

        if (!$stmt->execute()) {
            die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
        }
    }

    $stmt->close();
    $mysqli->close();
}

function upsertPush($commit)
{
    $mysqli = connectToDatabase();
    $sql = "SELECT Sequence FROM github_pushes WHERE HeadCommitId = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("s", $headCommitId);

    $headCommitId = $commit->HeadCommitId;

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $stmt->close();

    if ($row) {
        $sql = "UPDATE github_pushes SET ProcessingState = 'RE_REQUESTED', ProcessingDate = NULL WHERE Sequence = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $sequence);
        $sequence = $row["Sequence"];

        if (!$stmt->execute()) {
            die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
        }

    } else {
        $fields = array(
            "DeliveryId",
            "HookId",
            "TargetId",
            "TargetType",
            "RepositoryOwner",
            "RepositoryName",
            "Ref",
            "HeadCommitId",
            "HeadCommitTreeId",
            "HeadCommitMessage",
            "HeadCommitTimestamp",
            "HeadCommitAuthorName",
            "HeadCommitAuthorEmail",
            "HeadCommitCommitterName",
            "HeadCommitCommitterEmail",
            "InstallationId"
        );

        $sql = "INSERT INTO github_pushes (`" . implode("`,`", $fields) . "`) ";
        $sql .= "VALUES (unhex(replace(?, '-', '')), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param(
            'siissssssssssssi',
            $deliveryId,
            $hookId,
            $targetId,
            $targetType,
            $repositoryOwner,
            $repositoryName,
            $ref,
            $headCommitId,
            $headCommitTreeId,
            $headCommitMessage,
            $headCommitTimestamp,
            $headCommitAuthorName,
            $headCommitAuthorEmail,
            $headCommitCommitterName,
            $headCommitCommitterEmail,
            $installationId
        );

        $deliveryId = GUIDv4::random();
        $hookId = $commit->HookId;
        $targetId = $commit->TargetId;
        $targetType = $commit->TargetType;
        $repositoryOwner = $commit->RepositoryOwner;
        $repositoryName = $commit->RepositoryName;
        $ref = $commit->Ref;
        $headCommitId = $commit->HeadCommitId;
        $headCommitTreeId = $commit->HeadCommitTreeId;
        $headCommitMessage = $commit->HeadCommitMessage;
        $headCommitTimestamp = $commit->HeadCommitTimestamp;
        $headCommitAuthorName = $commit->HeadCommitAuthorName;
        $headCommitAuthorEmail = $commit->HeadCommitAuthorEmail;
        $headCommitCommitterName = $commit->HeadCommitCommitterName;
        $headCommitCommitterEmail = $commit->HeadCommitCommitterEmail;
        $installationId = $commit->InstallationId;

        if (!$stmt->execute()) {
            die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
        }
    }

    $stmt->close();
    $mysqli->close();
}
