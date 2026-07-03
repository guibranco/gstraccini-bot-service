<?php

// Usage statistics endpoint

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../../Library/DatabaseHandler.php';

$mySqlSecretsFile = __DIR__ . '/../../secrets/mySql.secrets.php';
if (file_exists($mySqlSecretsFile)) {
    require_once $mySqlSecretsFile;
}

if (!isset($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database not configured']);
    exit;
}

/**
 * Run a query and return the first row, or null if the query fails
 * (e.g. the underlying table is not present).
 */
function fetchRow(mysqli $mysqli, string $sql): ?array
{
    try {
        $result = $mysqli->query($sql);
        return $result ? $result->fetch_assoc() : null;
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

$pullRequests = fetchRow(
    $mysqli,
    "SELECT COUNT(*) AS total, SUM(Merged = 1) AS merged,
        ROUND(AVG(CASE WHEN Merged = 1 THEN TIMESTAMPDIFF(HOUR, CreatedAt, UpdatedAt) END), 1) AS avgMergeHours
     FROM github_pull_requests"
);

$issuesClosed = fetchRow(
    $mysqli,
    "SELECT COUNT(*) AS total FROM github_issues WHERE State = 'CLOSED'"
);

$commitsAnalyzed = fetchRow(
    $mysqli,
    "SELECT COUNT(*) AS total FROM github_pushes"
);

$activeRepositories = fetchRow(
    $mysqli,
    "SELECT COUNT(*) AS total FROM (
        SELECT RepositoryOwner, RepositoryName FROM github_pull_requests
        UNION
        SELECT RepositoryOwner, RepositoryName FROM github_issues
        UNION
        SELECT RepositoryOwner, RepositoryName FROM github_pushes
    ) AS repos"
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

header('Cache-Control: public, max-age=300');
http_response_code(200);
echo json_encode($stats);
