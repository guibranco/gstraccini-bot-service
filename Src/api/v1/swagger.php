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
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.32.6/swagger-ui.css" integrity="sha384-9Q2fpS+xeS4ffJy6CagnwoUl+4ldAYhOs9pgZuEKxypVModhmZFzeMlvVsAjf7uT" crossorigin="anonymous">
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist@5.32.6/swagger-ui-bundle.js" integrity="sha384-EYdOaiRwn44zNjrw+Tfs06qYz9BGQVo2f4/pLY5i7VorbjnZNhdplAbTBk8FXHUJ" crossorigin="anonymous"></script>
<script src="https://unpkg.com/swagger-ui-dist@5.32.6/swagger-ui-standalone-preset.js" integrity="sha384-49fpFaVrAWI/qdgl9Vv5E/4NXxRUiJX5vGuLws1NUpTWGtEqzWEx8gHTw2UTehFK" crossorigin="anonymous"></script>
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
