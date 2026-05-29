<?php

header('Content-Type: application/json');

$apiSecretsFile = __DIR__ . '/secrets/api.secrets.php';
if (file_exists($apiSecretsFile)) {
    require_once $apiSecretsFile;
}

$validJobs = ['branches', 'comments', 'issues', 'pullRequests', 'pushes', 'repositories', 'signature'];

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

if (!file_exists($workerDir . '/' . $jobName . '.php')) {
    http_response_code(500);
    echo json_encode(['error' => 'Worker script not found']);
    exit;
}

$cmd = 'cd ' . escapeshellarg($workerDir) . ' && php ' . escapeshellarg($jobName . '.php') . ' > /dev/null 2>&1 &';

if (function_exists('proc_open')) {
    $process = proc_open('bash -c ' . escapeshellarg($cmd), [], $pipes);
    if (is_resource($process)) {
        proc_close($process);
    }
} elseif (function_exists('shell_exec')) {
    shell_exec($cmd);
} elseif (function_exists('popen')) {
    $handle = popen($cmd, 'r');
    if ($handle !== false) {
        pclose($handle);
    }
} else {
    http_response_code(503);
    echo json_encode(['error' => 'No subprocess function available; enable proc_open, shell_exec, or popen in php.ini']);
    exit;
}

http_response_code(202);
echo json_encode(['status' => 'accepted', 'job' => $jobName]);
