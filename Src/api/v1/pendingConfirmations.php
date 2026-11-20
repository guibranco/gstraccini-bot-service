<?php

// Pending confirmations endpoint - lists the bot comments waiting for a user
// confirmation (version bump decision, .infisicalignore approval) for the UI,
// scoped to the installations of a user.
header('Content-Type: application/json');

$apiSecretsFile = __DIR__.'/secrets/api.secrets.php';
if (file_exists($apiSecretsFile) === true) {
    include_once $apiSecretsFile;
}

$providedKey = ($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($providedKey === '' || isset($apiKey) === false || hash_equals($apiKey, $providedKey) === false) {
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

$userId = ($_GET['userId'] ?? null);
if ($userId === null || ctype_digit((string) $userId) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid userId']);
    exit;
}

$userIdInt = (int) $userId;

$allowedStatuses = explode(',', 'pending,resolved,cancelled,all');

$status = ($_GET['status'] ?? 'pending');
if (in_array($status, $allowedStatuses, true) === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status, expected one of: '.implode(', ', $allowedStatuses)]);
    exit;
}

if (isset($workerDir) === false || is_dir($workerDir) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Worker directory not configured']);
    exit;
}

require_once $workerDir.'/Library/DatabaseHandler.php';

$mySqlSecretsFile = $workerDir.'/secrets/mySql.secrets.php';
if (file_exists($mySqlSecretsFile) === true) {
    include_once $mySqlSecretsFile;
}

if (isset($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase) === false) {
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
$sql = 'SELECT Sequence, Kind, RepositoryOwner, RepositoryName, InstallationId, PullRequestNumber,
        PullRequestTitle, PullRequestAuthor, CommentId, Url, Status, Resolution, ResolvedAt,
        ReminderCount, LastReminderAt, CreatedAt
    FROM pending_confirmations
    WHERE InstallationId IN (SELECT InstallationId FROM user_installations WHERE UserId = ? AND RemovedAt IS NULL)';
if ($status !== 'all') {
    $sql .= ' AND Status = ?';
}

$sql .= ' ORDER BY CreatedAt DESC LIMIT 100';

$stmt = $mysqli->prepare($sql);
if ($status === 'all') {
    $stmt->bind_param('i', $userIdInt);
} else {
    $stmt->bind_param('is', $userIdInt, $status);
}

if ($stmt->execute() === false) {
    $stmt->close();
    $mysqli->close();
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$result = $stmt->get_result();

$confirmations = [];
if ($result !== false) {
    $row = $result->fetch_assoc();
    while ($row !== null) {
        $item                    = [];
        $item['sequence']        = (int) $row['Sequence'];
        $item['kind']            = $row['Kind'];
        $item['repositoryOwner'] = $row['RepositoryOwner'];
        $item['repositoryName']  = $row['RepositoryName'];
        $item['installationId']  = (int) $row['InstallationId'];
        $item['pullRequestNumber'] = (int) $row['PullRequestNumber'];
        $item['pullRequestTitle']  = $row['PullRequestTitle'];
        $item['pullRequestAuthor'] = $row['PullRequestAuthor'];
        $item['commentId']         = (int) $row['CommentId'];
        $item['url']               = $row['Url'];
        $item['status']            = $row['Status'];
        $item['resolution']        = $row['Resolution'];
        $item['resolvedAt']        = $row['ResolvedAt'];
        $item['reminderCount']     = (int) $row['ReminderCount'];
        $item['lastReminderAt']    = $row['LastReminderAt'];
        $item['createdAt']         = $row['CreatedAt'];
        $confirmations[]           = $item;

        $row = $result->fetch_assoc();
    }

    $result->close();
}//end if

$stmt->close();
$mysqli->close();

http_response_code(200);
echo json_encode($confirmations);
