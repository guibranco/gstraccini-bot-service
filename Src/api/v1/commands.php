<?php

$commandsContent = file_get_contents("commands.json");
$commands = json_decode($commandsContent, true);

$format = $_GET['format'] ?? 'markdown';

function generateMarkdown($commands)
{
    $output = "## Available Commands\n";
    foreach ($commands as $command) {
        $output .= "### " . $command['command'] . "\n";
        $output .= "- **Description**: " . $command['description'] . "\n";
        $output .= "- **Parameters**: " . $command['parameters'] . "\n\n";
    }
    return $output;
}

function generateHTML($commands)
{
    $output = "<h2>Available Commands</h2>";
    foreach ($commands as $command) {
        $output .= "<h3>" . $command['command'] . "</h3>";
        $output .= "<p><strong>Description:</strong> " . $command['description'] . "</p>";
        $output .= "<p><strong>Parameters:</strong> " . $command['parameters'] . "</p>";
    }
    return $output;
}

if ($format === 'html') {
    header('Content-Type: text/html');
    echo generateHTML($commands);
} else {
    header('Content-Type: text/markdown');
    echo generateMarkdown($commands);
}
