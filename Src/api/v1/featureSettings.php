<?php

/**
 * Feature settings endpoints: read and update the bot's opt-in features per
 * GitHub account (account_settings) and per repository (repository_settings),
 * for the accounts and repositories of the signed-in user's installations.
 *
 *   GET /v1/account-settings?userId=                    accounts and their settings
 *   PUT /v1/account-settings/{accountId}?userId=        {"requestVersionBump": true|false}
 *   GET /v1/repository-settings?userId=[&accountId=]    repositories and their settings
 *   PUT /v1/repository-settings/{repositoryId}?userId=  {"requestVersionBump": true|false|null}
 *
 * The trailing slash is optional. A repository value of null inherits the
 * account; the effective value is the repository's when set, else the
 * account's, else false (opt-in).
 */

use GuiBranco\GStracciniBot\Library\FeatureSettings;

require_once __DIR__.'/includes/userApiBootstrap.php';
require_once $workerDir.'/Library/FeatureSettings.php';

$path = (parse_url(($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
$resource = null;
$matches = [];
if (preg_match('#/(account|repository)-settings(?:/(\d+))?/?$#', $path, $matches) === 1) {
    $resource = $matches[1];
}

if ($resource === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
    exit;
}

$resourceId = null;
if (isset($matches[2]) === true) {
    $resourceId = (int) $matches[2];
}


/**
 * Runs a SELECT with bound parameters and returns every row.
 *
 * @param mysqli $mysqli    The connection.
 * @param string $sql       The query.
 * @param string $types     The bind_param types.
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
    $rows = [];
    if ($result !== false) {
        $rows = $result->fetch_all(MYSQLI_ASSOC);
    }

    $stmt->close();

    return $rows;

}//end fetchFeatureSettingsRows()


/**
 * The GitHub accounts of the user's active installations, with their settings.
 * The current row of an installation is its most recent one (highest Sequence).
 *
 * @param mysqli       $mysqli    The connection.
 * @param integer      $userId    The signed-in user.
 * @param integer|null $accountId Restricts to one account when set.
 *
 * @return array The accounts.
 */
function fetchAccounts(mysqli $mysqli, int $userId, ?int $accountId): array
{
    $sql = 'SELECT DISTINCT gi.AccountId, gi.AccountLogin, gi.AccountType, acs.RequestVersionBump
            FROM user_installations uins
            INNER JOIN github_installations gi
                ON gi.Sequence = (SELECT MAX(Sequence) FROM github_installations WHERE InstallationId = uins.InstallationId)
            LEFT JOIN account_settings acs ON acs.AccountId = gi.AccountId
            WHERE uins.UserId = ? AND uins.RemovedAt IS NULL';
    $types = 'i';
    $params = [$userId];
    if ($accountId !== null) {
        $sql .= ' AND gi.AccountId = ?';
        $types .= 'i';
        $params[] = $accountId;
    }

    $sql .= ' ORDER BY gi.AccountLogin';

    $accounts = [];
    foreach (fetchFeatureSettingsRows($mysqli, $sql, $types, ...$params) as $row) {
        $account = [];
        $account['accountId'] = (int) $row['AccountId'];
        $account['accountLogin'] = $row['AccountLogin'];
        $account['accountType'] = $row['AccountType'];
        $account['requestVersionBump'] = (int) ($row['RequestVersionBump'] ?? 0) === 1;
        $accounts[] = $account;
    }

    return $accounts;

}//end fetchAccounts()


/**
 * The repositories of the user's active installations, with their settings.
 * The current row of a repository is its most recent one (highest Sequence);
 * repositories removed from the installation or deleted are left out.
 *
 * @param mysqli       $mysqli       The connection.
 * @param integer      $userId       The signed-in user.
 * @param integer|null $accountId    Restricts to one account when set.
 * @param integer|null $repositoryId Restricts to one repository when set.
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
    $types = 'i';
    $params = [$userId];
    if ($accountId !== null) {
        $sql .= ' AND gr.OwnerId = ?';
        $types .= 'i';
        $params[] = $accountId;
    }

    if ($repositoryId !== null) {
        $sql .= ' AND gr.Id = ?';
        $types .= 'i';
        $params[] = $repositoryId;
    }

    $sql .= ' ORDER BY gr.FullName';

    $featureSettings = new FeatureSettings();
    $repositories = [];
    foreach (fetchFeatureSettingsRows($mysqli, $sql, $types, ...$params) as $row) {
        $repositoryValue = null;
        if ($row['RepositoryValue'] !== null) {
            $repositoryValue = ((int) $row['RepositoryValue'] === 1);
        }

        $repository = [];
        $repository['repositoryId'] = (int) $row['Id'];
        $repository['fullName'] = $row['FullName'];
        $repository['private'] = (bool) $row['Private'];
        $repository['accountId'] = (int) $row['OwnerId'];
        $repository['accountLogin'] = $row['OwnerLogin'];
        $repository['requestVersionBump'] = $repositoryValue;
        $repository['effectiveRequestVersionBump'] = $featureSettings->resolve($row['RepositoryValue'], $row['AccountValue']);
        $repositories[] = $repository;
    }

    return $repositories;

}//end fetchRepositories()


/**
 * Validates the body of a PUT request.
 *
 * @param mixed $body     The decoded JSON body.
 * @param bool  $nullable Whether null (inherit the account) is accepted.
 *
 * @return string|null The error message, or null when the body is valid.
 */
function validateFeatureSettingsBody(mixed $body, bool $nullable): ?string
{
    if (is_array($body) === false || array_key_exists('requestVersionBump', $body) === false) {
        return 'Missing requestVersionBump';
    }

    $value = $body['requestVersionBump'];
    if (is_bool($value) === true || ($nullable === true && $value === null)) {
        return null;
    }

    if ($nullable === true) {
        return 'requestVersionBump must be true, false or null';
    }

    return 'requestVersionBump must be true or false';

}//end validateFeatureSettingsBody()


/**
 * Stores an account's setting, when the account is one of the user's.
 *
 * @param mysqli   $mysqli    The connection.
 * @param int      $userId    The signed-in user.
 * @param int      $accountId The account.
 * @param int|null $value     1 or 0.
 *
 * @return boolean False when the account is not one of the user's installations.
 */
function saveAccountSetting(mysqli $mysqli, int $userId, int $accountId, ?int $value): bool
{
    $accounts = fetchAccounts($mysqli, $userId, $accountId);
    if (count($accounts) === 0) {
        return false;
    }

    $accountLogin = $accounts[0]['accountLogin'];
    $stmt = $mysqli->prepare(
        'INSERT INTO account_settings (AccountId, AccountLogin, RequestVersionBump, UpdatedByUserId)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE AccountLogin = VALUES(AccountLogin),
            RequestVersionBump = VALUES(RequestVersionBump), UpdatedByUserId = VALUES(UpdatedByUserId)'
    );
    $stmt->bind_param('isii', $accountId, $accountLogin, $value, $userId);
    $stmt->execute();
    $stmt->close();

    return true;

}//end saveAccountSetting()


/**
 * Stores a repository's setting, when the repository is one of the user's.
 *
 * @param mysqli   $mysqli       The connection.
 * @param int      $userId       The signed-in user.
 * @param int      $repositoryId The repository.
 * @param int|null $value        1, 0, or null to inherit the account.
 *
 * @return boolean False when the repository is not one of the user's installations.
 */
function saveRepositorySetting(mysqli $mysqli, int $userId, int $repositoryId, ?int $value): bool
{
    $repositories = fetchRepositories($mysqli, $userId, null, $repositoryId);
    if (count($repositories) === 0) {
        return false;
    }

    $accountId = $repositories[0]['accountId'];
    $fullName = $repositories[0]['fullName'];
    $stmt = $mysqli->prepare(
        'INSERT INTO repository_settings (RepositoryId, AccountId, FullName, RequestVersionBump, UpdatedByUserId)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE AccountId = VALUES(AccountId), FullName = VALUES(FullName),
            RequestVersionBump = VALUES(RequestVersionBump), UpdatedByUserId = VALUES(UpdatedByUserId)'
    );
    $stmt->bind_param('iisii', $repositoryId, $accountId, $fullName, $value, $userId);
    $stmt->execute();
    $stmt->close();

    return true;

}//end saveRepositorySetting()


/**
 * Closes the connection and sends the JSON response.
 *
 * @param mysqli $mysqli     The connection.
 * @param int    $statusCode The HTTP status code.
 * @param mixed  $payload    The response body.
 *
 * @return void
 */
function respondFeatureSettings(mysqli $mysqli, int $statusCode, mixed $payload): void
{
    $mysqli->close();
    http_response_code($statusCode);
    echo json_encode($payload);

}//end respondFeatureSettings()


$method = ($_SERVER['REQUEST_METHOD'] ?? 'GET');

$accountFilter = null;
if (isset($_GET['accountId']) === true && ctype_digit((string) $_GET['accountId']) === true) {
    $accountFilter = (int) $_GET['accountId'];
}

if ($method === 'GET' && $resourceId === null) {
    try {
        if ($resource === 'account') {
            $data = fetchAccounts($mysqli, $userIdInt, null);
        }

        if ($resource === 'repository') {
            $data = fetchRepositories($mysqli, $userIdInt, $accountFilter, null);
        }
    } catch (\Exception $e) {
        respondFeatureSettings($mysqli, 503, ['error' => 'Database unavailable']);
        exit;
    }

    respondFeatureSettings($mysqli, 200, $data);
    exit;
}


/**
 * Validates and stores a PUT request.
 *
 * @param mysqli $mysqli     The connection.
 * @param int    $userId     The signed-in user.
 * @param string $resource   "account" or "repository".
 * @param int    $resourceId The account or repository id.
 * @param mixed  $body       The decoded JSON body.
 *
 * @return string|null The error message (an invalid body, or "... not found"), or null when stored.
 */
function updateFeatureSetting(mysqli $mysqli, int $userId, string $resource, int $resourceId, mixed $body): ?string
{
    $isAccount = ($resource === 'account');
    $bodyError = validateFeatureSettingsBody($body, ($isAccount === false));
    if ($bodyError !== null) {
        return $bodyError;
    }

    $value = null;
    if ($body['requestVersionBump'] !== null) {
        $value = (int) $body['requestVersionBump'];
    }

    if ($isAccount === true) {
        $saved = saveAccountSetting($mysqli, $userId, $resourceId, $value);
    }

    if ($isAccount === false) {
        $saved = saveRepositorySetting($mysqli, $userId, $resourceId, $value);
    }

    if ($saved === false) {
        return ucfirst($resource).' not found';
    }

    return null;

}//end updateFeatureSetting()


/**
 * Sends the response of a PUT request.
 *
 * @param mysqli      $mysqli      The connection.
 * @param string|null $updateError The error returned by updateFeatureSetting().
 *
 * @return void
 */
function respondFeatureSettingsUpdate(mysqli $mysqli, ?string $updateError): void
{
    if ($updateError === null) {
        respondFeatureSettings($mysqli, 200, ['status' => 'updated']);
        return;
    }

    $statusCode = 400;
    if (str_ends_with($updateError, 'not found') === true) {
        $statusCode = 404;
    }

    respondFeatureSettings($mysqli, $statusCode, ['error' => $updateError]);

}//end respondFeatureSettingsUpdate()


if ($method === 'PUT' && $resourceId !== null) {
    $body = json_decode(file_get_contents('php://input'), true);
    try {
        $updateError = updateFeatureSetting($mysqli, $userIdInt, $resource, $resourceId, $body);
    } catch (\Exception $e) {
        respondFeatureSettings($mysqli, 503, ['error' => 'Database unavailable']);
        exit;
    }

    respondFeatureSettingsUpdate($mysqli, $updateError);
    exit;
}

$allowedMethod = 'PUT';
if ($resourceId === null) {
    $allowedMethod = 'GET';
}

header('Allow: '.$allowedMethod);
respondFeatureSettings($mysqli, 405, ['error' => 'Method Not Allowed']);
