<?php

// Auth endpoint - password login, TOTP 2FA, recovery codes, and password
// reset. FIDO/WebAuthn is intentionally NOT implemented here (no vetted
// WebAuthn library could be installed in this environment - hand-rolling
// that cryptography would be a security risk). The website's FIDO option
// remains a front-end-only demo until a library is available.
//
// Email delivery is not wired up: password-reset "request" generates and
// stores a real, time-limited token but does not send it anywhere yet
// (see the `devResetToken` field, which must be removed once a real mail
// provider is integrated).

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
use GuiBranco\GStracciniBot\Library\Totp;

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

const RECOVERY_CODE_COUNT = 10;
const PASSWORD_RESET_TTL_SECONDS = 1800;
const TOTP_ISSUER = "GStraccini Bot";

function findUserIdByEmail(mysqli $mysqli, string $email): ?int
{
    $stmt = $mysqli->prepare("SELECT UserId FROM github_users WHERE Email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row !== null ? (int) $row['UserId'] : null;
}

function isTotpEnabled(mysqli $mysqli, int $userId): bool
{
    $stmt = $mysqli->prepare("SELECT Enabled FROM user_totp WHERE UserId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row !== null && (bool) $row['Enabled'];
}

function verifyTotpForUser(mysqli $mysqli, CryptoHelper $crypto, int $userId, string $code): bool
{
    $stmt = $mysqli->prepare("SELECT EncryptedSecret FROM user_totp WHERE UserId = ? AND Enabled = 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row === null) {
        return false;
    }

    try {
        $secret = $crypto->decrypt($row['EncryptedSecret']);
    } catch (\RuntimeException $e) {
        return false;
    }

    return (new Totp())->verifyCode($secret, $code);
}

/**
 * Verifies a code against a user's TOTP secret regardless of whether it has
 * been enabled yet - used by the enable step, where Enabled is still 0.
 */
function verifyTotpForUnconfirmedSecret(mysqli $mysqli, CryptoHelper $crypto, int $userId, string $code): bool
{
    $stmt = $mysqli->prepare("SELECT EncryptedSecret FROM user_totp WHERE UserId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row === null) {
        return false;
    }

    try {
        $secret = $crypto->decrypt($row['EncryptedSecret']);
    } catch (\RuntimeException $e) {
        return false;
    }

    return (new Totp())->verifyCode($secret, $code);
}

function verifyRecoveryCodeForUser(mysqli $mysqli, int $userId, string $code): bool
{
    $stmt = $mysqli->prepare(
        "SELECT Sequence, CodeHash FROM user_recovery_codes WHERE UserId = ? AND UsedAt IS NULL"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $matchedSequence = null;
    while ($result && ($row = $result->fetch_assoc())) {
        if (password_verify($code, $row['CodeHash'])) {
            $matchedSequence = (int) $row['Sequence'];
            break;
        }
    }
    $stmt->close();

    if ($matchedSequence === null) {
        return false;
    }

    $updateStmt = $mysqli->prepare("UPDATE user_recovery_codes SET UsedAt = NOW() WHERE Sequence = ?");
    $updateStmt->bind_param("i", $matchedSequence);
    $updateStmt->execute();
    $updateStmt->close();

    return true;
}

$dbHandler = new DatabaseHandler($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);

try {
    $mysqli = $dbHandler->connect();
} catch (\Exception $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Database unavailable']);
    exit;
}

try {
    $crypto = new CryptoHelper();
} catch (\RuntimeException $e) {
    $mysqli->close();
    http_response_code(500);
    echo json_encode(['error' => 'Encryption is not configured']);
    exit;
}

$segments = array_values(array_filter(explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))));
$authIndex = array_search('auth', $segments, true);
$route = $authIndex !== false ? implode('/', array_slice($segments, $authIndex + 1)) : '';
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input'), true) ?? [];

function requireUserId(mysqli $mysqli): int
{
    $userId = $_GET['userId'] ?? null;
    if ($userId === null || !ctype_digit((string) $userId)) {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid userId']);
        exit;
    }
    return (int) $userId;
}

// POST /auth/login/  { email, password } -> { status: ok|totp_required, userId } | 401
if ($route === 'login' && $method === 'POST') {
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    $userId = $email !== '' ? findUserIdByEmail($mysqli, $email) : null;
    $passwordHash = null;
    if ($userId !== null) {
        $stmt = $mysqli->prepare("SELECT PasswordHash FROM user_credentials WHERE UserId = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $passwordHash = $row['PasswordHash'] ?? null;
    }

    if ($userId === null || $passwordHash === null || !password_verify($password, $passwordHash)) {
        $mysqli->close();
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password']);
        exit;
    }

    $totpRequired = isTotpEnabled($mysqli, $userId);
    $mysqli->close();

    http_response_code(200);
    echo json_encode(['status' => $totpRequired ? 'totp_required' : 'ok', 'userId' => $userId]);
    exit;
}

// POST /auth/login/verify-totp/  { userId, code } -> { status: ok } | 401
if ($route === 'login/verify-totp' && $method === 'POST') {
    $userId = isset($body['userId']) && ctype_digit((string) $body['userId']) ? (int) $body['userId'] : null;
    $code = (string) ($body['code'] ?? '');

    if ($userId === null || !verifyTotpForUser($mysqli, $crypto, $userId, $code)) {
        $mysqli->close();
        http_response_code(401);
        echo json_encode(['error' => 'Invalid code']);
        exit;
    }

    $mysqli->close();
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

// POST /auth/login/verify-recovery/  { userId, code } -> { status: ok } | 401
if ($route === 'login/verify-recovery' && $method === 'POST') {
    $userId = isset($body['userId']) && ctype_digit((string) $body['userId']) ? (int) $body['userId'] : null;
    $code = (string) ($body['code'] ?? '');

    if ($userId === null || !verifyRecoveryCodeForUser($mysqli, $userId, $code)) {
        $mysqli->close();
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or already-used recovery code']);
        exit;
    }

    $mysqli->close();
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    exit;
}

// PUT /auth/password/?userId=  { currentPassword?, newPassword } -> { status: updated }
if ($route === 'password' && $method === 'PUT') {
    $userId = requireUserId($mysqli);
    $currentPassword = (string) ($body['currentPassword'] ?? '');
    $newPassword = (string) ($body['newPassword'] ?? '');

    if (strlen($newPassword) < 6) {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => 'New password must be at least 6 characters long']);
        exit;
    }

    $stmt = $mysqli->prepare("SELECT PasswordHash FROM user_credentials WHERE UserId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row !== null && !password_verify($currentPassword, $row['PasswordHash'])) {
        $mysqli->close();
        http_response_code(403);
        echo json_encode(['error' => 'Current password is incorrect']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $mysqli->prepare(
        "INSERT INTO user_credentials (UserId, PasswordHash) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE PasswordHash = VALUES(PasswordHash)"
    );
    $stmt->bind_param("is", $userId, $newHash);
    $stmt->execute();
    $stmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode(['status' => 'updated']);
    exit;
}

// POST /auth/totp/setup/?userId=  -> { secret, otpauthUri }
if ($route === 'totp/setup' && $method === 'POST') {
    $userId = requireUserId($mysqli);

    $totp = new Totp();
    $secret = $totp->generateSecret();
    $encryptedSecret = $crypto->encrypt($secret);

    $stmt = $mysqli->prepare(
        "INSERT INTO user_totp (UserId, EncryptedSecret, Enabled) VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE EncryptedSecret = VALUES(EncryptedSecret), Enabled = 0"
    );
    $stmt->bind_param("is", $userId, $encryptedSecret);
    $stmt->execute();
    $stmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode([
        'secret' => $secret,
        'otpauthUri' => $totp->getProvisioningUri($secret, (string) $userId, TOTP_ISSUER),
    ]);
    exit;
}

// POST /auth/totp/enable/?userId=  { code } -> { status: enabled } | 400
if ($route === 'totp/enable' && $method === 'POST') {
    $userId = requireUserId($mysqli);
    $code = (string) ($body['code'] ?? '');

    if (!verifyTotpForUnconfirmedSecret($mysqli, $crypto, $userId, $code)) {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => 'Invalid code']);
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE user_totp SET Enabled = 1 WHERE UserId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode(['status' => 'enabled']);
    exit;
}

// POST /auth/totp/disable/?userId=  { code } -> { status: disabled } | 400
if ($route === 'totp/disable' && $method === 'POST') {
    $userId = requireUserId($mysqli);
    $code = (string) ($body['code'] ?? '');

    if (!verifyTotpForUser($mysqli, $crypto, $userId, $code) && !verifyRecoveryCodeForUser($mysqli, $userId, $code)) {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => 'Invalid code']);
        exit;
    }

    $stmt = $mysqli->prepare("UPDATE user_totp SET Enabled = 0 WHERE UserId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode(['status' => 'disabled']);
    exit;
}

// GET /auth/totp/status/?userId=  -> { enabled }
if ($route === 'totp/status' && $method === 'GET') {
    $userId = requireUserId($mysqli);
    $enabled = isTotpEnabled($mysqli, $userId);
    $mysqli->close();

    http_response_code(200);
    echo json_encode(['enabled' => $enabled]);
    exit;
}

// POST /auth/recovery-codes/generate/?userId=  -> { codes: [...] } (shown once)
if ($route === 'recovery-codes/generate' && $method === 'POST') {
    $userId = requireUserId($mysqli);

    $stmt = $mysqli->prepare("DELETE FROM user_recovery_codes WHERE UserId = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();

    $codes = [];
    $insertStmt = $mysqli->prepare("INSERT INTO user_recovery_codes (UserId, CodeHash) VALUES (?, ?)");
    for ($i = 0; $i < RECOVERY_CODE_COUNT; $i++) {
        $code = strtoupper(bin2hex(random_bytes(5)));
        $codes[] = $code;
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $insertStmt->bind_param("is", $userId, $codeHash);
        $insertStmt->execute();
    }
    $insertStmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode(['codes' => $codes]);
    exit;
}

// POST /auth/password-reset/request/  { email } -> { status: ok, devResetToken? }
if ($route === 'password-reset/request' && $method === 'POST') {
    $email = trim((string) ($body['email'] ?? ''));
    $userId = $email !== '' ? findUserIdByEmail($mysqli, $email) : null;

    $devResetToken = null;
    if ($userId !== null) {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL_SECONDS);

        $stmt = $mysqli->prepare(
            "INSERT INTO user_password_resets (UserId, TokenHash, ExpiresAt) VALUES (?, ?, ?)"
        );
        $stmt->bind_param("iss", $userId, $tokenHash, $expiresAt);
        $stmt->execute();
        $stmt->close();

        // TODO: send $token to the user's email once a mail provider is wired up.
        error_log("Password reset requested for userId={$userId}; token generation is real, sending is not yet implemented.");
        $devResetToken = $token;
    }

    $mysqli->close();

    http_response_code(200);
    $response = ['status' => 'ok'];
    if ($devResetToken !== null) {
        $response['devResetToken'] = $devResetToken;
    }
    echo json_encode($response);
    exit;
}

// POST /auth/password-reset/verify/  { token, newPassword } -> { status: updated } | 400
if ($route === 'password-reset/verify' && $method === 'POST') {
    $token = (string) ($body['token'] ?? '');
    $newPassword = (string) ($body['newPassword'] ?? '');

    if ($token === '' || strlen($newPassword) < 6) {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => 'Invalid token or password too short']);
        exit;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $mysqli->prepare(
        "SELECT Sequence, UserId FROM user_password_resets
         WHERE TokenHash = ? AND UsedAt IS NULL AND ExpiresAt > NOW() LIMIT 1"
    );
    $stmt->bind_param("s", $tokenHash);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row === null) {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }

    $userId = (int) $row['UserId'];
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $mysqli->prepare(
        "INSERT INTO user_credentials (UserId, PasswordHash) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE PasswordHash = VALUES(PasswordHash)"
    );
    $stmt->bind_param("is", $userId, $newHash);
    $stmt->execute();
    $stmt->close();

    $stmt = $mysqli->prepare("UPDATE user_password_resets SET UsedAt = NOW() WHERE Sequence = ?");
    $stmt->bind_param("i", $row['Sequence']);
    $stmt->execute();
    $stmt->close();
    $mysqli->close();

    http_response_code(200);
    echo json_encode(['status' => 'updated']);
    exit;
}

$mysqli->close();
http_response_code(404);
echo json_encode(['error' => 'Route not found']);
