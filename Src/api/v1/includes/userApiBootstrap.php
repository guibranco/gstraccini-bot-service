<?php

// Shared bootstrap of the user-scoped API endpoints, required at file scope
// so the variables it sets stay in the endpoint's scope. It checks the API
// key, loads the worker libraries and the database secrets, validates the
// userId query parameter and connects to the database. On any failure it
// responds with the JSON error and exits.
//
// Sets: $workerDir, $userIdInt (the signed-in user's GitHub id), $mysqli.

header('Content-Type: application/json');

$apiSecretsFile = __DIR__ . '/../secrets/api.secrets.php';
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

$userIdParam = $_GET['userId'] ?? null;
if ($userIdParam === null || !ctype_digit((string) $userIdParam)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid userId']);
    exit;
}
$userIdInt = (int) $userIdParam;

try {
    $mysqli = (new DatabaseHandler($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase))->connect();
} catch (\Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}
