<?php

$commandsContent = file_get_contents("commands.json");
$commands = json_decode($commandsContent, true);

$format = $_GET['format'] ?? 'markdown';

function generateMarkdown($commands)
{
    $output = "## Available Commands\n\n";
    foreach ($commands as $command) {
        $output .= "### " . htmlspecialchars($command['command']) . "\n";
        $output .= "- **Description**: " . htmlspecialchars($command['description']) . "\n";
        
        if (isset($command['parameters']) && is_array($command['parameters'])) {
            $output .= "- **Parameters**:\n";
            foreach ($command['parameters'] as $param) {
                $required = isset($param['required']) && $param['required'] ? " (Required)" : " (Optional)";
                $output .= "  - `" . htmlspecialchars($param['parameter']) . "`" . $required . ": " . htmlspecialchars($param['description']) . "\n";
            }
        }
        
        if (isset($command['requiresPullRequestOpen']) && $command['requiresPullRequestOpen']) {
            $output .= "- **Requires Pull Request Open**: Yes\n";
        }
        
        if (isset($command['dev']) && $command['dev']) {
            $output .= "- **Developer Command**: Yes\n";
        }
        
        $output .= "\n";
    }
    return $output;
}

function generateHTML($commands)
{
    $output = "<!DOCTYPE html>\n<html lang='en'>\n<head>\n";
    $output .= "<meta charset='UTF-8'>\n";
    $output .= "<meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
    $output .= "<title>Available Commands</title>\n";
    $output .= "<style>\n";
    $output .= "body { font-family: Arial, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; }\n";
    $output .= "h2 { color: #333; border-bottom: 1px solid #ddd; padding-bottom: 10px; }\n";
    $output .= "h3 { color: #0366d6; margin-top: 25px; }\n";
    $output .= ".param-name { font-family: monospace; background: #f6f8fa; padding: 2px 5px; border-radius: 3px; }\n";
    $output .= ".required { color: #d73a49; }\n";
    $output .= ".optional { color: #6a737d; }\n";
    $output .= ".tag { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; margin-right: 5px; }\n";
    $output .= ".pr-required { background: #ffebe9; color: #cf222e; }\n";
    $output .= ".dev-command { background: #ddf4ff; color: #0969da; }\n";
    $output .= "</style>\n";
    $output .= "</head>\n<body>\n";
    
    $output .= "<h2>Available Commands</h2>\n";
    
    foreach ($commands as $command) {
        $output .= "<div class='command'>\n";
        $output .= "<h3>" . htmlspecialchars($command['command']);
        
        // Add tags for PR required and dev command
        if (isset($command['requiresPullRequestOpen']) && $command['requiresPullRequestOpen']) {
            $output .= " <span class='tag pr-required'>PR Required</span>";
        }
        if (isset($command['dev']) && $command['dev']) {
            $output .= " <span class='tag dev-command'>Developer</span>";
        }
        
        $output .= "</h3>\n";
        $output .= "<p><strong>Description:</strong> " . htmlspecialchars($command['description']) . "</p>\n";
        
        if (isset($command['parameters']) && is_array($command['parameters'])) {
            $output .= "<p><strong>Parameters:</strong></p>\n<ul>\n";
            foreach ($command['parameters'] as $param) {
                $requiredClass = isset($param['required']) && $param['required'] ? "required" : "optional";
                $requiredText = isset($param['required']) && $param['required'] ? "Required" : "Optional";
                $output .= "<li><span class='param-name'>" . htmlspecialchars($param['parameter']) . "</span> - ";
                $output .= "<span class='" . $requiredClass . "'>(" . $requiredText . ")</span>: ";
                $output .= htmlspecialchars($param['description']) . "</li>\n";
            }
            $output .= "</ul>\n";
        } else {
            $output .= "<p><em>No parameters required</em></p>\n";
        }
        
        $output .= "</div>\n";
    }
    
    $output .= "</body>\n</html>";
    return $output;
}

function generateSimpleList($commands)
{
    $output = "AVAILABLE COMMANDS\n\n";
    foreach ($commands as $command) {
        $output .= $command['command'] . " - " . $command['description'] . "\n";
    }
    return $output;
}

function generateJSON($commands)
{
    return json_encode($commands, JSON_PRETTY_PRINT);
}

switch ($format) {
    case 'html':
        header('Content-Type: text/html');
        echo generateHTML($commands);
        break;
    case 'json':
        header('Content-Type: application/json');
        echo generateJSON($commands);
        break;
    case 'text':
        header('Content-Type: text/plain');
        echo generateSimpleList($commands);
        break;
    case 'markdown':
    default:
        header('Content-Type: text/markdown');
        echo generateMarkdown($commands);
        break;
}
