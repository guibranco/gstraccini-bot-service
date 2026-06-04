<?php

declare(strict_types=1);

$secretsFile = __DIR__ . '/secrets/install.secrets.php';
if (!file_exists($secretsFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'Installer not configured', 'timestamp' => date('c')]);
    exit;
}

require_once $secretsFile;

header('Content-Type: application/json; charset=utf-8');

$providedToken = $_GET['token'] ?? '';
if (empty($installToken) || !hash_equals($installToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized', 'timestamp' => date('c')]);
    exit;
}

$serviceDir   = rtrim($serviceDir, '/');
$serviceZip   = $serviceDir . '/service.zip';
$apiZip       = __DIR__ . '/api.zip';
$lockFile     = $serviceDir . '/updating.lock';
$releaseFile  = $serviceDir . '/updating.release';
$errors       = [];
$log          = [];

file_put_contents($lockFile, date('Y-m-d H:i:s'));
$log[] = 'Lock created';

if (file_exists($serviceZip)) {
    $zip    = new ZipArchive();
    $result = $zip->open($serviceZip);
    if ($result === true) {
        $zip->extractTo($serviceDir);
        $zip->close();
        unlink($serviceZip);
        $log[] = 'Service extracted';
    } else {
        $errors[] = "Failed to open service.zip (ZipArchive code: {$result})";
    }
} else {
    $errors[] = "service.zip not found: {$serviceZip}";
}

if (file_exists($apiZip)) {
    $zip    = new ZipArchive();
    $result = $zip->open($apiZip);
    if ($result === true) {
        $zip->extractTo(__DIR__);
        $zip->close();
        unlink($apiZip);
        $log[] = 'API extracted';
    } else {
        $errors[] = "Failed to open api.zip (ZipArchive code: {$result})";
    }
} else {
    $errors[] = "api.zip not found: {$apiZip}";
}

file_put_contents($releaseFile, date('Y-m-d H:i:s'));
@unlink($lockFile);
$log[] = 'Lock released';

$success = empty($errors);
if (!$success) {
    http_response_code(500);
}

echo json_encode([
    'success'   => $success,
    'log'       => $log,
    'errors'    => $errors,
    'timestamp' => date('c'),
]);
