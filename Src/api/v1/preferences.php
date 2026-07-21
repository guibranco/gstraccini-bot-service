<?php

// Preferences endpoint - reads and updates the signed-in user's UI
// preferences (theme, whether lists are grouped by organization).

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

$userId = $_GET['userId'] ?? null;
if ($userId === null || !ctype_digit((string) $userId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid userId']);
    exit;
}
$userIdInt = (int) $userId;

$dbHandler = new DatabaseHandler($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);

try {
    $mysqli = $dbHandler->connect();
} catch (\Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $mysqli->prepare("SELECT Theme, GroupByOrganization FROM user_preferences WHERE UserId = ?");
    $stmt->bind_param("i", $userIdInt);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode([
        'theme' => $row['Theme'] ?? 'system',
        'groupByOrganization' => $row === null ? true : (bool) $row['GroupByOrganization'],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $theme = $body['theme'] ?? 'system';
    if (!in_array($theme, ['light', 'dark', 'system'], true)) {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => 'Invalid theme']);
        exit;
    }

    $groupByOrganization = !empty($body['groupByOrganization']) ? 1 : 0;

    $stmt = $mysqli->prepare(
        "INSERT INTO user_preferences (UserId, Theme, GroupByOrganization) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE Theme = VALUES(Theme), GroupByOrganization = VALUES(GroupByOrganization)"
    );
    $stmt->bind_param("isi", $userIdInt, $theme, $groupByOrganization);
    $stmt->execute();
    $stmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode(['status' => 'updated']);
    exit;
}

$mysqli->close();
http_response_code(405);
header('Allow: GET, PUT');
echo json_encode(['error' => 'Method Not Allowed']);
