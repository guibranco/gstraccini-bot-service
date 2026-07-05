<?php

header('Content-Type: text/html; charset=UTF-8');

$versionFile = __DIR__ . '/version.txt';
$version = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : '1.0.0';
$pageTitle = 'GStraccini Bot API v' . htmlspecialchars($version) . ' - Swagger UI';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $pageTitle ?></title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.32.8/swagger-ui.css" integrity="sha384-9Q2fpS+xeS4ffJy6CagnwoUl+4ldAYhOs9pgZuEKxypVModhmZFzeMlvVsAjf7uT" crossorigin="anonymous">
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist@5.32.8/swagger-ui-bundle.js" integrity="sha384-IKpAWwsTL0pcw7/Amtnt2eXF4P1BK64WNuY2E/RG15SWLUW5HXzFuyqCSAr/DP8C" crossorigin="anonymous"></script>
<script src="https://unpkg.com/swagger-ui-dist@5.32.8/swagger-ui-standalone-preset.js" integrity="sha384-sm24U+dUFhSIgEfhSy6d7F66jTzh7YHwjwcdFANJ87OCxOWdQPERHk3xR2MtzMLa" crossorigin="anonymous"></script>
<script>
  window.onload = function () {
    SwaggerUIBundle({
      url: '/v1/openapi.yaml',
      dom_id: '#swagger-ui',
      presets: [
        SwaggerUIBundle.presets.apis,
        SwaggerUIStandalonePreset
      ],
      layout: 'StandaloneLayout',
      deepLinking: true,
      displayRequestDuration: true,
      filter: true
    });
  };
</script>
</body>
</html>
