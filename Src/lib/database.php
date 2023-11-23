<?php

function connectToDatabase()
{
    global $mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase;

    $mysqli = new mysqli($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);
    if ($mysqli->connect_errno) {
        die("Failed to connect to MySQL: " . $mysqli->connect_error);
    }

    $mysqli->set_charset("utf8mb4");

    return $mysqli;
}

function readTable($tableName, $where = null)
{
    $mysqli = connectToDatabase();
    $defaultWhere = "Processed = 0 ORDER BY Sequence ASC LIMIT 10";
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

    $data = $result->fetch_all(MYSQLI_ASSOC);
    $result->close();
    $mysqli->close();

    return $data;
}

function updateTable($tableName, $sequence)
{
    $mysqli = connectToDatabase();
    $sql = "UPDATE " . $tableName . " SET Processed = 1, ProcessedDate = NOW() WHERE Sequence = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $sequence);

    $stmt->execute();
    $stmt->close();
    $mysqli->close();
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
        $sql = "UPDATE github_pull_requests SET Processed = 0, ProcessedDate = NULL WHERE Sequence = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $sequence);
        $sequence = $row;
        $stmt->execute();
    } else {
        $sql = "INSERT INTO github_pull_requests (GitHubHookId, GitHubHookInstallationTargetId, RepositoryOwner, RepositoryName, PullRequestId, PullRequestNumber, PullRequestSubmitter, NodeId, Title, Ref, InstallationId) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('iissiissssi', $githubId, $gitHubInstallationId, $repositoryOwner, $repositoryName, $pullRequestId, $pullRequestNumber, $pullRequestSubmitter, $nodeId, $title, $ref, $installationId);

        $githubId = $pullRequest->HookId;
        $gitHubInstallationId = $pullRequest->HookInstallationTargetId;
        $repositoryOwner = $pullRequest->RepositoryOwner;
        $repositoryName = $pullRequest->RepositoryName;
        $pullRequestId = $pullRequest->Id;
        $pullRequestNumber = $pullRequest->Number;
        $pullRequestSubmitter = $pullRequest->Submitter;
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
