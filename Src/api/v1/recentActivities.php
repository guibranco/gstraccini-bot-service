<?php

// Recent activities endpoint - lists the most recent bot activity
// (merged PRs, closed issues, comments, pushes), scoped to the
// installations the given user has access to.

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

$userId = $_GET['userId'] ?? null;
if ($userId === null || !ctype_digit((string) $userId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid userId']);
    exit;
}
$userIdInt = (int) $userId;

$limit = 20;
if (isset($_GET['limit']) && ctype_digit((string) $_GET['limit'])) {
    $limit = max(1, min(100, (int) $_GET['limit']));
}

$dbHandler = new DatabaseHandler($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);

try {
    $mysqli = $dbHandler->connect();
} catch (\Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$stmt = $mysqli->prepare(
    "SELECT Sequence, RepositoryOwner, RepositoryName, InstallationId, ActionType, Title, Url,
        PullRequestId, PullRequestNumber, PullRequestNodeId, IssueId, IssueNumber, IssueNodeId, CreatedAt
     FROM recent_activities
     WHERE InstallationId IN (SELECT InstallationId FROM user_installations WHERE UserId = ? AND RemovedAt IS NULL)
     ORDER BY CreatedAt DESC
     LIMIT ?"
);
$stmt->bind_param("ii", $userIdInt, $limit);
$stmt->execute();
$result = $stmt->get_result();

$activities = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $activities[] = [
            'sequence' => (int) $row['Sequence'],
            'repositoryOwner' => $row['RepositoryOwner'],
            'repositoryName' => $row['RepositoryName'],
            'installationId' => $row['InstallationId'] !== null ? (int) $row['InstallationId'] : null,
            'actionType' => $row['ActionType'],
            'title' => $row['Title'],
            'url' => $row['Url'],
            'pullRequestId' => $row['PullRequestId'] !== null ? (int) $row['PullRequestId'] : null,
            'pullRequestNumber' => $row['PullRequestNumber'] !== null ? (int) $row['PullRequestNumber'] : null,
            'pullRequestNodeId' => $row['PullRequestNodeId'],
            'issueId' => $row['IssueId'] !== null ? (int) $row['IssueId'] : null,
            'issueNumber' => $row['IssueNumber'] !== null ? (int) $row['IssueNumber'] : null,
            'issueNodeId' => $row['IssueNodeId'],
            'createdAt' => $row['CreatedAt'],
        ];
    }
    $result->close();
}

$stmt->close();
$mysqli->close();

http_response_code(200);
echo json_encode($activities);
