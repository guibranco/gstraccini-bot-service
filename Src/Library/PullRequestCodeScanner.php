<?php

namespace GuiBranco\GStracciniBot\Library;

class PullRequestCodeScanner
{
    public function scanDiffForKeywords($diffContent): array
    {
        $lines = explode(PHP_EOL, $diffContent);
        $files = [];
        $currentFile = null;
        $currentLine = null;

        private const COMMENT_MARKERS = ['//', '#', '%', ';', '--', '<!--', '/*', '*'];
        private const KEYWORDS = ['bug', 'fixme', 'todo'];

        private function parseCommentLine(string $line): ?array {
            foreach (self::COMMENT_MARKERS as $marker) {
                if (($pos = strpos($line, $marker)) !== false) {
                    $comment = trim(substr($line, $pos + strlen($marker)));
                    foreach (self::KEYWORDS as $keyword) {
                        if (preg_match("/\b$keyword\b(:|\s+)(?<description>.+)?/i", $comment, $matches)) {
                            return [
                                'category' => strtolower($keyword),
                                'description' => $matches['description'] ?? ''
                            ];
                        }
                    }
                }
            }
            return null;
        }

        foreach ($lines as $line) {
            if (preg_match('/^\+\+\+ b\/(.+)/', $line, $matches)) {
                $currentFile = $matches[1];
            }

            if (preg_match('/^@@ -\d+,\d+ \+(\d+),\d+ @@/', $line, $matches)) {
                $currentLine = (int)$matches[1];
            }
            if (strpos($line, '+') === 0) {
                $currentLine++;
            }

            if ($currentFile && $currentLine && preg_match('/^\+(.*)/', $line, $matches)) {
                $codeLine = $matches[1];
                if (preg_match($commentPattern, $codeLine)) {
                    $files[$currentFile][] = trim($codeLine) . " - line: " . $line;
                }
            }
        }

        return $files;
    }

    public function generateReport(array $files): string
    {
        if (empty($files)) {
            return "No 'bug', 'fixme' or 'todo' comments found in the pull request.";
        }

        $report = "Found the following comments with 'bug', 'fixme', or 'todo':\n";
        foreach ($files as $file => $lines) {
            foreach ($lines as $line) {
                $report .= "- {$line} ({$file})\n";
            }
        }

        return $report;
    }
}
