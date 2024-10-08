<?php

namespace GuiBranco\GStracciniBot\Library;

class PullRequestCodeScanner
{
    public function scanDiffForKeywords($diff): array
    {
        $lines = explode(PHP_EOL, $diffContent);
        $files = [];
        $currentFile = null;

        $commentPatterns = [
            '/\bbug\b/i',
            '/\bfixme\b/i',
            '/\btodo\b/i',
        ];

        foreach ($lines as $line) {
            if (preg_match('/^\+\+\+ b\/(.+)/', $line, $matches)) {
                $currentFile = $matches[1];
            }

            if ($currentFile && preg_match('/^\+(.*)/', $line, $matches)) {
                $codeLine = $matches[1];
                foreach ($commentPatterns as $pattern) {
                    if (preg_match($pattern, $codeLine)) {
                        $files[$currentFile][] = trim($codeLine);
                        break;
                    }
                }
            }
        }

        return $files;
    }

    public function generateReport(array $files): string
    {
        if (empty($files)) {
            return "No 'bug', 'todo', or 'fixme' comments found in the pull request.";
        }

        $report = "Found the following comments with 'bug', 'todo', or 'fixme':\n";
        foreach ($files as $file => $lines) {
            foreach ($lines as $line) {
                $report .= "- {$line} ({$file})\n";
            }
        }

        return $report;
    }
}
