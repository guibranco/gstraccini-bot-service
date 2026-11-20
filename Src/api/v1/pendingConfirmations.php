<?php

// Pending confirmations endpoint - lists the bot comments waiting for a user
// confirmation (version bump decision, .infisicalignore approval) for the UI,
// scoped to the installations of a user.

header('Content-Type: application/json');

$apiSecretsFile = __DIR__ . '/secrets/api.secrets.php';
if (file_exists($apiSecretsFile)) {
    require_once $apiSecretsFile;
}

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($providedKey) || !isset($apiKey) || !hash_equals($apiKey, $providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$userId = $_GET['userId'] ?? null;
if ($userId === null || !ctype_digit((string) $userId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid userId']);
    exit;
}
$userIdInt = (int) $userId;

$allowedStatuses = ['pending', 'resolved', 'cancelled', 'all'];
$status = $_GET['status'] ?? 'pending';
if (!in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status, expected one of: ' . implode(', ', $allowedStatuses)]);
    exit;
}

if (!isset($workerDir) || !is_dir($workerDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Worker directory not configured']);
    exit;
}

require_once $workerDir . '/Library/DatabaseHandler.php';

$mySqlSecretsFile = $workerDir . '/secrets/mySql.secrets.php';
if (file_exists($mySqlSecretsFile)) {
    require_once $mySqlSecretsFile;
}

if (!isset($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database not configured']);
    exit;
}

$dbHandler = new DatabaseHandler($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);

try {
    $mysqli = $dbHandler->connect();
} catch (\Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

// Closed items are history: only the last 100 are listed.
$sql = "SELECT Sequence, Kind, RepositoryOwner, RepositoryName, InstallationId, PullRequestNumber,
        PullRequestTitle, PullRequestAuthor, CommentId, Url, Status, Resolution, ResolvedAt,
        ReminderCount, LastReminderAt, CreatedAt
    FROM pending_confirmations
    WHERE InstallationId IN (SELECT InstallationId FROM user_installations WHERE UserId = ? AND RemovedAt IS NULL)";
if ($status !== 'all') {
    $sql .= " AND Status = ?";
}
$sql .= " ORDER BY CreatedAt DESC LIMIT 100";

$stmt = $mysqli->prepare($sql);
if ($status === 'all') {
    $stmt->bind_param("i", $userIdInt);
} else {
    $stmt->bind_param("is", $userIdInt, $status);
}

if (!$stmt->execute()) {
    $stmt->close();
    $mysqli->close();
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$result = $stmt->get_result();

$confirmations = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $confirmations[] = [
            'sequence' => (int) $row['Sequence'],
            'kind' => $row['Kind'],
            'repositoryOwner' => $row['RepositoryOwner'],
            'repositoryName' => $row['RepositoryName'],
            'installationId' => (int) $row['InstallationId'],
            'pullRequestNumber' => (int) $row['PullRequestNumber'],
            'pullRequestTitle' => $row['PullRequestTitle'],
            'pullRequestAuthor' => $row['PullRequestAuthor'],
            'commentId' => (int) $row['CommentId'],
            'url' => $row['Url'],
            'status' => $row['Status'],
            'resolution' => $row['Resolution'],
            'resolvedAt' => $row['ResolvedAt'],
            'reminderCount' => (int) $row['ReminderCount'],
            'lastReminderAt' => $row['LastReminderAt'],
            'createdAt' => $row['CreatedAt'],
        ];
    }
    $result->close();
}

$stmt->close();
$mysqli->close();

http_response_code(200);
echo json_encode($confirmations);
