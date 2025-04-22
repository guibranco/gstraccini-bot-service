<?php
// Health check endpoint
header('Content-Type: text/plain');
http_response_code(200);
echo 'Healthy';
