<?php

// Sample data structure for commands
$commands = [
    [
        'command' => '/start',
        'description' => 'Initializes the process.',
        'parameters' => 'None',
        'examples' => '',
    ],
    [
        'command' => '/stop',
        'description' => 'Stops the current process.',
        'parameters' => 'force (optional): boolean, whether to force stop.',
        'examples' => '',
    ],
];

// Get the requested format
$format = $_GET['format'] ?? 'markdown';

// Function to generate Markdown
function generateMarkdown($commands) {
    $output = "## Available Commands\n";
    foreach ($commands as $command) {
        $output .= "### " . $command['command'] . "\n";
        $output .= "- **Description**: " . $command['description'] . "\n";
        $output .= "- **Parameters**: " . $command['parameters'] . "\n\n";
    }
    return $output;
}

// Function to generate HTML
function generateHTML($commands) {
    $output = "<h2>Available Commands</h2>";
    foreach ($commands as $command) {
        $output .= "<h3>" . $command['command'] . "</h3>";
        $output .= "<p><strong>Description:</strong> " . $command['description'] . "</p>";
        $output .= "<p><strong>Parameters:</strong> " . $command['parameters'] . "</p>";
    }
    return $output;
}

// Output the commands in the requested format
if ($format === 'html') {
    header('Content-Type: text/html');
    echo generateHTML($commands);
} else {
    header('Content-Type: text/markdown');
    echo generateMarkdown($commands);
}

?>
