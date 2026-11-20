<?php

// Feature settings endpoints - read and update the bot's opt-in features
// per GitHub account (account_settings) and per repository
// (repository_settings), for the accounts and repositories of the signed-in
// user's installations.
//
//   GET /v1/account-settings/?userId=                      accounts and their settings
//   PUT /v1/account-settings/{accountId}/?userId=          {"requestVersionBump": true|false}
//   GET /v1/repository-settings/?userId=[&accountId=]      repositories and their settings
//   PUT /v1/repository-settings/{repositoryId}/?userId=    {"requestVersionBump": true|false|null}
//
// A repository value of null inherits the account; the effective value is
// the repository's when set, else the account's, else false (opt-in).

use GuiBranco\GStracciniBot\Library\FeatureSettings;

require_once __DIR__ . '/includes/userApiBootstrap.php';
require_once $workerDir . '/Library/FeatureSettings.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
if (preg_match('#/account-settings/(?:(\d+)/)?$#', $path, $matches) === 1) {
    $resource = 'account';
} elseif (preg_match('#/repository-settings/(?:(\d+)/)?$#', $path, $matches) === 1) {
    $resource = 'repository';
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
    exit;
}
$resourceId = isset($matches[1]) ? (int) $matches[1] : null;

/**
 * Runs a SELECT with bound parameters and returns every row.
 *
 * @param mysqli $mysqli The connection.
 * @param string $sql    The query.
 * @param string $types  The bind_param types.
 * @param mixed  ...$params The parameters.
 *
 * @return array The rows, as associative arrays.
 */
function fetchFeatureSettingsRows(mysqli $mysqli, string $sql, string $types, mixed ...$params): array
{
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    return $rows;
}

/**
 * The GitHub accounts of the user's active installations, with their settings.
 * The current row of an installation is its most recent one (highest Sequence).
 *
 * @param mysqli   $mysqli    The connection.
 * @param int      $userId    The signed-in user.
 * @param int|null $accountId Restricts to one account when set.
 *
 * @return array The accounts.
 */
function fetchAccounts(mysqli $mysqli, int $userId, ?int $accountId): array
{
    $sql = "SELECT DISTINCT gi.AccountId, gi.AccountLogin, gi.AccountType, acs.RequestVersionBump
            FROM user_installations uins
            INNER JOIN github_installations gi
                ON gi.Sequence = (SELECT MAX(Sequence) FROM github_installations WHERE InstallationId = uins.InstallationId)
            LEFT JOIN account_settings acs ON acs.AccountId = gi.AccountId
            WHERE uins.UserId = ? AND uins.RemovedAt IS NULL";
    $types = "i";
    $params = [$userId];
    if ($accountId !== null) {
        $sql .= " AND gi.AccountId = ?";
        $types .= "i";
        $params[] = $accountId;
    }
    $sql .= " ORDER BY gi.AccountLogin";

    $accounts = [];
    foreach (fetchFeatureSettingsRows($mysqli, $sql, $types, ...$params) as $row) {
        $accounts[] = [
            'accountId' => (int) $row['AccountId'],
            'accountLogin' => $row['AccountLogin'],
            'accountType' => $row['AccountType'],
            'requestVersionBump' => FeatureSettings::resolve(null, $row['RequestVersionBump']),
        ];
    }

    return $accounts;
}

/**
 * The repositories of the user's active installations, with their settings.
 * The current row of a repository is its most recent one (highest Sequence);
 * repositories removed from the installation or deleted are left out.
 *
 * @param mysqli   $mysqli       The connection.
 * @param int      $userId       The signed-in user.
 * @param int|null $accountId    Restricts to one account when set.
 * @param int|null $repositoryId Restricts to one repository when set.
 *
 * @return array The repositories.
 */
function fetchRepositories(mysqli $mysqli, int $userId, ?int $accountId, ?int $repositoryId): array
{
    $sql = "SELECT DISTINCT gr.Id, gr.FullName, gr.Private, gr.OwnerId, gr.OwnerLogin,
                   rs.RequestVersionBump AS RepositoryValue, acs.RequestVersionBump AS AccountValue
            FROM user_installations uins
            INNER JOIN github_repositories gr
                ON gr.InstallationId = uins.InstallationId
                AND gr.Sequence = (SELECT MAX(Sequence) FROM github_repositories WHERE Id = gr.Id)
            LEFT JOIN repository_settings rs ON rs.RepositoryId = gr.Id
            LEFT JOIN account_settings acs ON acs.AccountId = gr.OwnerId
            WHERE uins.UserId = ? AND uins.RemovedAt IS NULL
              AND (gr.InstallationAction IS NULL OR gr.InstallationAction <> 'removed')
              AND gr.LastAction <> 'deleted'";
    $types = "i";
    $params = [$userId];
    if ($accountId !== null) {
        $sql .= " AND gr.OwnerId = ?";
        $types .= "i";
        $params[] = $accountId;
    }
    if ($repositoryId !== null) {
        $sql .= " AND gr.Id = ?";
        $types .= "i";
        $params[] = $repositoryId;
    }
    $sql .= " ORDER BY gr.FullName";

    $repositories = [];
    foreach (fetchFeatureSettingsRows($mysqli, $sql, $types, ...$params) as $row) {
        $repositoryValue = $row['RepositoryValue'] === null ? null : ((int) $row['RepositoryValue'] === 1);
        $repositories[] = [
            'repositoryId' => (int) $row['Id'],
            'fullName' => $row['FullName'],
            'private' => (bool) $row['Private'],
            'accountId' => (int) $row['OwnerId'],
            'accountLogin' => $row['OwnerLogin'],
            'requestVersionBump' => $repositoryValue,
            'effectiveRequestVersionBump' => FeatureSettings::resolve($row['RepositoryValue'], $row['AccountValue']),
        ];
    }

    return $repositories;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $resourceId === null) {
    try {
        if ($resource === 'account') {
            $data = fetchAccounts($mysqli, $userIdInt, null);
        } else {
            $accountFilter = isset($_GET['accountId']) && ctype_digit((string) $_GET['accountId']) ? (int) $_GET['accountId'] : null;
            $data = fetchRepositories($mysqli, $userIdInt, $accountFilter, null);
        }
    } catch (\Exception $e) {
        $mysqli->close();
        http_response_code(503);
        echo json_encode(['error' => 'Database unavailable']);
        exit;
    }

    $mysqli->close();
    http_response_code(200);
    echo json_encode($data);
    exit;
}

if ($method === 'PUT' && $resourceId !== null) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !array_key_exists('requestVersionBump', $body)) {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => 'Missing requestVersionBump']);
        exit;
    }

    $value = $body['requestVersionBump'];
    $nullable = $resource === 'repository';
    if (!is_bool($value) && !($nullable && $value === null)) {
        $mysqli->close();
        http_response_code(400);
        echo json_encode(['error' => $nullable ? 'requestVersionBump must be true, false or null' : 'requestVersionBump must be true or false']);
        exit;
    }
    $valueInt = $value === null ? null : ($value ? 1 : 0);

    try {
        if ($resource === 'account') {
            $accounts = fetchAccounts($mysqli, $userIdInt, $resourceId);
            if (count($accounts) === 0) {
                $mysqli->close();
                http_response_code(404);
                echo json_encode(['error' => 'Account not found']);
                exit;
            }

            $accountLogin = $accounts[0]['accountLogin'];
            $stmt = $mysqli->prepare(
                "INSERT INTO account_settings (AccountId, AccountLogin, RequestVersionBump, UpdatedByUserId)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    AccountLogin = VALUES(AccountLogin),
                    RequestVersionBump = VALUES(RequestVersionBump),
                    UpdatedByUserId = VALUES(UpdatedByUserId)"
            );
            $stmt->bind_param("isii", $resourceId, $accountLogin, $valueInt, $userIdInt);
        } else {
            $repositories = fetchRepositories($mysqli, $userIdInt, null, $resourceId);
            if (count($repositories) === 0) {
                $mysqli->close();
                http_response_code(404);
                echo json_encode(['error' => 'Repository not found']);
                exit;
            }

            $accountId = $repositories[0]['accountId'];
            $fullName = $repositories[0]['fullName'];
            $stmt = $mysqli->prepare(
                "INSERT INTO repository_settings (RepositoryId, AccountId, FullName, RequestVersionBump, UpdatedByUserId)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    AccountId = VALUES(AccountId),
                    FullName = VALUES(FullName),
                    RequestVersionBump = VALUES(RequestVersionBump),
                    UpdatedByUserId = VALUES(UpdatedByUserId)"
            );
            $stmt->bind_param("iisii", $resourceId, $accountId, $fullName, $valueInt, $userIdInt);
        }

        $stmt->execute();
        $stmt->close();
    } catch (\Exception $e) {
        $mysqli->close();
        http_response_code(503);
        echo json_encode(['error' => 'Database unavailable']);
        exit;
    }

    $mysqli->close();
    http_response_code(200);
    echo json_encode(['status' => 'updated']);
    exit;
}

$mysqli->close();
http_response_code(405);
header('Allow: ' . ($resourceId === null ? 'GET' : 'PUT'));
echo json_encode(['error' => 'Method Not Allowed']);
