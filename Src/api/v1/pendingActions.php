<?php

// Pending actions endpoint - lists unread pending actions for the UI,
// and lets the UI flag a specific one as read.

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

$segments = array_values(array_filter(explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))));
$resourceIndex = array_search('pending-actions', $segments, true);
$sequence = $resourceIndex !== false ? ($segments[$resourceIndex + 1] ?? null) : null;
$isReadAction = $resourceIndex !== false && ($segments[$resourceIndex + 2] ?? null) === 'read';

if ($sequence !== null && $isReadAction) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['error' => 'Method Not Allowed']);
        $mysqli->close();
        exit;
    }

    if (!ctype_digit($sequence)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid sequence']);
        $mysqli->close();
        exit;
    }

    $sequenceInt = (int) $sequence;
    $stmt = $mysqli->prepare("UPDATE pending_actions SET IsRead = TRUE, ReadAt = NOW() WHERE Sequence = ? AND IsRead = FALSE");
    $stmt->bind_param("i", $sequenceInt);
    $stmt->execute();
    $updated = $stmt->affected_rows === 1;
    $stmt->close();
    $mysqli->close();

    if (!$updated) {
        http_response_code(404);
        echo json_encode(['error' => 'Pending action not found or already read']);
        exit;
    }

    http_response_code(200);
    echo json_encode(['status' => 'read', 'sequence' => $sequenceInt]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method Not Allowed']);
    $mysqli->close();
    exit;
}

$result = $mysqli->query(
    "SELECT Sequence, RepositoryOwner, RepositoryName, ActionType, Title, Description, Url,
        PullRequestId, PullRequestNumber, PullRequestNodeId, IsRead, CreatedAt
     FROM pending_actions
     WHERE IsRead = FALSE
     ORDER BY CreatedAt DESC"
);

$pendingActions = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingActions[] = [
            'sequence' => (int) $row['Sequence'],
            'repositoryOwner' => $row['RepositoryOwner'],
            'repositoryName' => $row['RepositoryName'],
            'actionType' => $row['ActionType'],
            'title' => $row['Title'],
            'description' => $row['Description'],
            'url' => $row['Url'],
            'pullRequestId' => $row['PullRequestId'] !== null ? (int) $row['PullRequestId'] : null,
            'pullRequestNumber' => $row['PullRequestNumber'] !== null ? (int) $row['PullRequestNumber'] : null,
            'pullRequestNodeId' => $row['PullRequestNodeId'],
            'isRead' => (bool) $row['IsRead'],
            'createdAt' => $row['CreatedAt'],
        ];
    }
    $result->close();
}

$mysqli->close();

http_response_code(200);
echo json_encode($pendingActions);
