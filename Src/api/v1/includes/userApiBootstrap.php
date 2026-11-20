<?php

/**
 * Shared bootstrap of the user-scoped API endpoints, required at file scope so
 * the variables it sets stay in the endpoint's scope. It checks the API key,
 * loads the worker library and the database secrets, validates the userId
 * query parameter and connects to the database. On any failure it responds
 * with the JSON error and exits.
 *
 * Sets: $workerDir, $userIdInt (the signed-in user's GitHub id), $mysqli.
 */


/**
 * Finds why a user-scoped API request can't go on, from what the bootstrap gathered.
 *
 * @param array $request The request: apiKey (configured), providedKey, workerDir, databaseConfigured, userId.
 *
 * @return string|null The error message, or null when the request can go on.
 */
function findUserApiRequestError(array $request): ?string
{
    $apiKey = (string) ($request['apiKey'] ?? '');
    if ($apiKey === '' || hash_equals($apiKey, (string) $request['providedKey']) === false) {
        return 'Unauthorized';
    }

    if (is_dir((string) $request['workerDir']) === false) {
        return 'Worker directory not configured';
    }

    if ($request['databaseConfigured'] === false) {
        return 'Database not configured';
    }

    if ($request['userId'] === false) {
        return 'Missing or invalid userId';
    }

    return null;

}//end findUserApiRequestError()


/**
 * Sends a JSON error response, with the HTTP status code of the error.
 *
 * @param string $error The error message returned by findUserApiRequestError(), or "Database unavailable".
 *
 * @return void
 */
function sendUserApiError(string $error): void
{
    $statusCode = match ($error) {
        'Unauthorized' => 401,
        'Missing or invalid userId' => 400,
        'Database unavailable' => 503,
        default => 500,
    };

    http_response_code($statusCode);
    echo json_encode(['error' => $error]);

}//end sendUserApiError()


header('Content-Type: application/json');

$apiSecretsFile = __DIR__.'/../secrets/api.secrets.php';
if (is_file($apiSecretsFile) === true) {
    include_once $apiSecretsFile;
}

$workerDir = ($workerDir ?? '');
if (is_file($workerDir.'/secrets/mySql.secrets.php') === true) {
    include_once $workerDir.'/secrets/mySql.secrets.php';
}

$userIdInt = filter_var(($_GET['userId'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

$userApiRequest = [];
$userApiRequest['apiKey'] = ($apiKey ?? '');
$userApiRequest['providedKey'] = ($_SERVER['HTTP_X_API_KEY'] ?? '');
$userApiRequest['workerDir'] = $workerDir;
$userApiRequest['databaseConfigured'] = isset($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);
$userApiRequest['userId'] = $userIdInt;

$userApiError = findUserApiRequestError($userApiRequest);
if ($userApiError !== null) {
    sendUserApiError($userApiError);
    exit;
}

require_once $workerDir.'/Library/DatabaseHandler.php';

try {
    $mysqli = (new DatabaseHandler($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase))->connect();
} catch (\Exception $e) {
    sendUserApiError('Database unavailable');
    exit;
}
