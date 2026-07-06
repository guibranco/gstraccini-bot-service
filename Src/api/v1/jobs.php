<?php

header('Content-Type: application/json');

$apiSecretsFile = __DIR__ . '/secrets/api.secrets.php';
if (file_exists($apiSecretsFile)) {
    require_once $apiSecretsFile;
}

$validJobs = ['branches', 'comments', 'installations', 'issues', 'pullRequests', 'pushes', 'repositories', 'users'];

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($providedKey) || !isset($apiKey) || !hash_equals($apiKey, $providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$segments = array_values(array_filter(explode('/', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))));
$jobName = end($segments);

if (!in_array($jobName, $validJobs, true)) {
    http_response_code(404);
    echo json_encode(['error' => 'Job not found', 'available' => $validJobs]);
    exit;
}

if (!isset($workerDir) || !is_dir($workerDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Worker directory not configured']);
    exit;
}

$workerFile = "$workerDir/$jobName.php";

if (!file_exists($workerFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Worker script not found']);
    exit;
}

http_response_code(202);
echo json_encode(['status' => 'accepted', 'job' => $jobName]);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

ignore_user_abort(true);
set_time_limit(0);

chdir($workerDir);
require_once $workerFile;
