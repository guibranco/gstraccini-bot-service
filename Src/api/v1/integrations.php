<?php

// Integrations endpoint - lists, adds, and removes the signed-in user's
// third-party provider API keys (SonarCloud, Snyk, Codacy, etc). Keys are
// encrypted at rest (AES-256-GCM) and never returned in full once stored.

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

require_once $workerDir . '/vendor/autoload.php';
require_once $workerDir . '/Library/DatabaseHandler.php';

use GuiBranco\GStracciniBot\Library\CryptoHelper;

$mySqlSecretsFile = $workerDir . '/secrets/mySql.secrets.php';
if (file_exists($mySqlSecretsFile)) {
    require_once $mySqlSecretsFile;
}

$encryptionSecretsFile = $workerDir . '/secrets/encryption.secrets.php';
if (file_exists($encryptionSecretsFile)) {
    require_once $encryptionSecretsFile;
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

try {
    $crypto = new CryptoHelper();
} catch (\RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Encryption is not configured']);
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

/**
 * Masks an API key for display: first 4 and last 4 characters visible,
 * the rest replaced with asterisks.
 */
function maskApiKey(string $apiKey): string
{
    $visibleLength = 4;
    if (strlen($apiKey) <= $visibleLength * 2) {
        return str_repeat('*', strlen($apiKey));
    }
    $maskedLength = strlen($apiKey) - ($visibleLength * 2);
    return substr($apiKey, 0, $visibleLength) . str_repeat('*', $maskedLength) . substr($apiKey, -$visibleLength);
}

$segments = array_values(array_filter(explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))));
$resourceIndex = array_search('integrations', $segments, true);
$providerSegment = $resourceIndex !== false ? ($segments[$resourceIndex + 1] ?? null) : null;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $mysqli->prepare(
        "SELECT Provider, EncryptedApiKey, Status, LastUsedAt, LastError
         FROM user_integrations WHERE UserId = ? ORDER BY Provider ASC"
    );
    $stmt->bind_param("i", $userIdInt);
    $stmt->execute();
    $result = $stmt->get_result();

    $integrations = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            try {
                $maskedKey = maskApiKey($crypto->decrypt($row['EncryptedApiKey']));
            } catch (\RuntimeException $e) {
                $maskedKey = null;
            }

            $integrations[] = [
                'provider' => $row['Provider'],
                'maskedApiKey' => $maskedKey,
                'status' => $row['Status'],
                'lastUsedAt' => $row['LastUsedAt'],
                'lastError' => $row['LastError'],
            ];
        }
        $result->close();
    }

    $stmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode($integrations);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $provider = trim((string) ($body['provider'] ?? ''));
    $apiKeyValue = (string) ($body['apiKey'] ?? '');

    if ($provider === '' || strlen($apiKeyValue) < 10) {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => 'Please provide a provider and an API key of at least 10 characters']);
        exit;
    }

    $encryptedApiKey = $crypto->encrypt($apiKeyValue);

    $stmt = $mysqli->prepare(
        "INSERT INTO user_integrations (UserId, Provider, EncryptedApiKey, Status)
         VALUES (?, ?, ?, 'Validated')
         ON DUPLICATE KEY UPDATE EncryptedApiKey = VALUES(EncryptedApiKey), Status = 'Validated', LastError = NULL"
    );
    $stmt->bind_param("iss", $userIdInt, $provider, $encryptedApiKey);
    $stmt->execute();
    $stmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode(['status' => 'saved', 'provider' => $provider]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if ($providerSegment === null || $providerSegment === '') {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => 'Missing provider']);
        exit;
    }

    $provider = urldecode($providerSegment);
    $stmt = $mysqli->prepare("DELETE FROM user_integrations WHERE UserId = ? AND Provider = ?");
    $stmt->bind_param("is", $userIdInt, $provider);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    $mysqli->close();

    if (!$deleted) {
        http_response_code(404);
        echo json_encode(['error' => 'Integration not found']);
        exit;
    }

    http_response_code(200);
    echo json_encode(['status' => 'removed', 'provider' => $provider]);
    exit;
}

$mysqli->close();
http_response_code(405);
header('Allow: GET, POST, DELETE');
echo json_encode(['error' => 'Method Not Allowed']);
