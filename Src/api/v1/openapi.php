<?php

header('Content-Type: application/yaml');
header('Access-Control-Allow-Origin: *');

$versionFile = __DIR__ . '/version.txt';
$version = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : '1.0.0';

$yaml = file_get_contents(__DIR__ . '/openapi.yaml');
echo str_replace('__APP_VERSION__', $version, $yaml);
