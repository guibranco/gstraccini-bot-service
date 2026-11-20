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
 * Checks the API key, loads the worker library and the secrets, validates the
 * userId query parameter and connects to the database. The secrets files are
 * included inside this function, so the variables they set are declared
 * global first to stay visible to the endpoint.
 *
 * @return array|null The HTTP status code and error message, or null when the request can go on.
 */
function bootstrapUserApi(): ?array
{
    global $apiKey, $workerDir, $mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase, $userIdInt, $mysqli;

    $apiSecretsFile = __DIR__.'/../secrets/api.secrets.php';
    if (is_file($apiSecretsFile) === true) {
        include_once $apiSecretsFile;
    }

    $apiKeyHeader = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($apiKeyHeader === '' || isset($apiKey) === false || hash_equals($apiKey, $apiKeyHeader) === false) {
        return [401, 'Unauthorized'];
    }

    if (isset($workerDir) === false || is_dir($workerDir) === false) {
        return [500, 'Worker directory not configured'];
    }

    include_once $workerDir.'/Library/DatabaseHandler.php';
    $mySqlSecretsFile = $workerDir.'/secrets/mySql.secrets.php';
    if (is_file($mySqlSecretsFile) === true) {
        include_once $mySqlSecretsFile;
    }

    if (isset($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase) === false) {
        return [500, 'Database not configured'];
    }

    $userIdInt = filter_var(($_GET['userId'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($userIdInt === false) {
        return [400, 'Missing or invalid userId'];
    }

    try {
        $handler = new DatabaseHandler($mySqlHost, $mySqlUser, $mySqlPassword, $mySqlDatabase);
        $mysqli  = $handler->connect();
    } catch (\Exception $e) {
        return [503, 'Database unavailable'];
    }

    return null;

}//end bootstrapUserApi()


header('Content-Type: application/json');

$bootstrapError = bootstrapUserApi();
if ($bootstrapError !== null) {
    http_response_code($bootstrapError[0]);
    echo json_encode(['error' => $bootstrapError[1]]);
    exit;
}
