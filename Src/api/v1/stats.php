<?php

// Usage statistics endpoint

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

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

$userId = $_GET['userId'] ?? null;
if ($userId === null || !ctype_digit((string) $userId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid userId']);
    exit;
}
$userIdInt = (int) $userId;

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

/**
 * Run a query scoped to the given user's installations (via user_installations)
 * and return the first row, or null if the query fails (e.g. the underlying
 * table is not present).
 */
function fetchRowForUser(mysqli $mysqli, string $sql, int $userId): ?array
{
    try {
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            return null;
        }
        $placeholderCount = substr_count($sql, '?');
        $types = str_repeat("i", $placeholderCount);
        $params = array_fill(0, $placeholderCount, $userId);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        return $row;
    } catch (\mysqli_sql_exception $e) {
        return null;
    }
}

$dbHandler = new DatabaseHandler($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);

try {
    $mysqli = $dbHandler->connect();
} catch (\Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

$installationFilter = "InstallationId IN (SELECT InstallationId FROM user_installations WHERE UserId = ?)";

$pullRequests = fetchRowForUser(
    $mysqli,
    "SELECT COUNT(*) AS total, SUM(Merged = 1) AS merged,
        ROUND(AVG(CASE WHEN Merged = 1 THEN TIMESTAMPDIFF(HOUR, CreatedAt, UpdatedAt) END), 1) AS avgMergeHours
     FROM github_pull_requests
     WHERE {$installationFilter}",
    $userIdInt
);

$issuesClosed = fetchRowForUser(
    $mysqli,
    "SELECT COUNT(*) AS total FROM github_issues WHERE State = 'CLOSED' AND {$installationFilter}",
    $userIdInt
);

$commitsAnalyzed = fetchRowForUser(
    $mysqli,
    "SELECT COUNT(*) AS total FROM github_pushes WHERE {$installationFilter}",
    $userIdInt
);

$activeRepositories = fetchRowForUser(
    $mysqli,
    "SELECT COUNT(*) AS total FROM (
        SELECT RepositoryOwner, RepositoryName FROM github_pull_requests WHERE {$installationFilter}
        UNION
        SELECT RepositoryOwner, RepositoryName FROM github_issues WHERE {$installationFilter}
        UNION
        SELECT RepositoryOwner, RepositoryName FROM github_pushes WHERE {$installationFilter}
    ) AS repos",
    $userIdInt
);

$mysqli->close();

$stats = [
    'totalPullRequests' => (int) ($pullRequests['total'] ?? 0),
    'pullRequestsMerged' => (int) ($pullRequests['merged'] ?? 0),
    'commitsAnalyzed' => (int) ($commitsAnalyzed['total'] ?? 0),
    'issuesClosed' => (int) ($issuesClosed['total'] ?? 0),
    'averageTimeToMergeHours' => $pullRequests['avgMergeHours'] !== null ? (float) $pullRequests['avgMergeHours'] : 0,
    'activeRepositories' => (int) ($activeRepositories['total'] ?? 0),
];

http_response_code(200);
echo json_encode($stats);
