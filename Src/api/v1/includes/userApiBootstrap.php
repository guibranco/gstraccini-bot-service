<?php

// Shared bootstrap of the user-scoped API endpoints, required at file scope
// so the variables it sets stay in the endpoint's scope. It checks the API
// key, loads the worker libraries and the database secrets, validates the
// userId query parameter and connects to the database. On any failure it
// responds with the JSON error and exits.
//
// Sets: $workerDir, $userIdInt (the signed-in user's GitHub id), $mysqli.

/**
 * Responds with a JSON error and ends the request.
 *
 * @param int    $statusCode The HTTP status code.
 * @param string $message    The error message.
 *
 * @return never
 */
function failUserApiRequest(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    echo json_encode(['error' => $message]);
    exit;
}

header('Content-Type: application/json');

foreach ([__DIR__ . '/../secrets/api.secrets.php'] as $secretsFile) {
    if (is_file($secretsFile) === true) {
        require_once $secretsFile;
    }
}

$apiKeyHeader = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($apiKeyHeader === '' || isset($apiKey) === false || hash_equals($apiKey, $apiKeyHeader) === false) {
    failUserApiRequest(401, 'Unauthorized');
}

if (isset($workerDir) === false || is_dir($workerDir) === false) {
    failUserApiRequest(500, 'Worker directory not configured');
}

require_once $workerDir . '/Library/DatabaseHandler.php';
foreach ([$workerDir . '/secrets/mySql.secrets.php'] as $secretsFile) {
    if (is_file($secretsFile) === true) {
        require_once $secretsFile;
    }
}

if (isset($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase) === false) {
    failUserApiRequest(500, 'Database not configured');
}

$userIdInt = filter_var($_GET['userId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($userIdInt === false) {
    failUserApiRequest(400, 'Missing or invalid userId');
}

try {
    $mysqli = (new DatabaseHandler($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase))->connect();
} catch (\Exception $e) {
    failUserApiRequest(503, 'Database unavailable');
}
