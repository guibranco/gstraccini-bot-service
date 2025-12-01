<?php

/**
 * Command Documentation Generator
 *
 * This script reads a commands.json file and formats the commands in various output formats:
 * - Markdown (default)
 * - HTML
 * - Plain text
 * - JSON
 *
 * Usage:
 *   /path/to/commands.php                  # Default markdown output
 *   /path/to/commands.php?format=markdown  # Markdown output
 *   /path/to/commands.php?format=simplemd  # Simple/tiny Markdown output
 *   /path/to/commands.php?format=html      # HTML output
 *   /path/to/commands.php?format=text      # Simple text list
 *   /path/to/commands.php?format=json      # JSON output
 *
 * @author Guilherme Branco Stracini guilherme(at)guilhermebranco(dot)com(dot)br
 * @version 1.0
 */

// Load and parse the commands file
$commandsContent = file_get_contents("commands.json");
$commands = json_decode($commandsContent, true);

// Get the requested output format (default to markdown)
$format = $_GET['format'] ?? 'markdown';

/**
 * Generates markdown documentation for the commands
 *
 * @param array $commands Array of command objects
 * @return string Formatted markdown
 */
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

/**
 * Generates simple markdown documentation for the commands
 *
 * @param array $commands Array of command objects
 * @return string Formatted markdown
 */
function generateSimpleMarkdown($commands)
{
    $output = "## Available Commands\n\n";
    foreach ($commands as $command) {
        $output .= "- `@gstraccini " . $command['command'] . "`: " . $command['description'] . "\n";
    }
    return $output;
}

/**
 * Simple function to convert basic Markdown to HTML
 *
 * Handles common Markdown features found in the descriptions:
 * - Links: [text](url)
 * - Code: `code`
 * - Bold: **text**
 * - Italic: *text*
 *
 * @param string $markdown Markdown text to convert
 * @return string Converted HTML
 */
function markdownToHtml($markdown)
{
    $text = htmlspecialchars($markdown);

    // Convert links [text](url) to <a href="url">text</a>
    $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank">$1</a>', $text);

    // Convert inline code `text` to <code>text</code>
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

    // Convert bold **text** to <strong>text</strong>
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);

    // Convert italic *text* to <em>text</em> (careful not to match **)
    $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);

    return $text;
}

/**
 * Generates HTML documentation for the commands
 *
 * Creates a complete HTML page with styling and formatted command details.
 * Converts Markdown in descriptions to HTML.
 *
 * @param array $commands Array of command objects
 * @return string Complete HTML document
 */
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
    $output .= "code { background: #f6f8fa; padding: 2px 5px; border-radius: 3px; font-family: monospace; }\n";
    $output .= "a { color: #0366d6; text-decoration: none; }\n";
    $output .= "a:hover { text-decoration: underline; }\n";
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
        $output .= "<p><strong>Description:</strong> " . markdownToHtml($command['description']) . "</p>\n";

        if (isset($command['parameters']) && is_array($command['parameters'])) {
            $output .= "<p><strong>Parameters:</strong></p>\n<ul>\n";
            foreach ($command['parameters'] as $param) {
                $requiredClass = isset($param['required']) && $param['required'] ? "required" : "optional";
                $requiredText = isset($param['required']) && $param['required'] ? "Required" : "Optional";
                $output .= "<li><span class='param-name'>" . htmlspecialchars($param['parameter']) . "</span> - ";
                $output .= "<span class='" . $requiredClass . "'>(" . $requiredText . ")</span>: ";
                $output .= markdownToHtml($param['description']) . "</li>\n";
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

/**
 * Generates a simple text list of commands and descriptions
 *
 * @param array $commands Array of command objects
 * @return string Text listing each command and its description
 */
function generateSimpleList($commands)
{
    $output = "AVAILABLE COMMANDS\n\n";
    foreach ($commands as $command) {
        $output .= $command['command'] . " - " . $command['description'] . "\n";
    }
    return $output;
}

/**
 * Outputs the commands as formatted JSON
 *
 * @param array $commands Array of command objects
 * @return string JSON-encoded string of commands
 */
function generateJSON($commands)
{
    return json_encode($commands, JSON_PRETTY_PRINT);
}

// Output the commands in the requested format
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
    case 'simplemd':
        header('Content-Type: text/markdown');
        echo generateSimpleMarkdown($commands);
        break;
    case 'markdown':
    default:
        header('Content-Type: text/markdown');
        echo generateMarkdown($commands);
        break;
}
