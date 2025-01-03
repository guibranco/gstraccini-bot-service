<?php

namespace GuiBranco\GStracciniBot\Library;

class PullRequestCodeScanner
{
    private const COMMENT_MARKERS = ['//', '#', '%', ';', '--', '<!--', '/*', '*'];
    private const KEYWORDS = ['bug', 'fixme', 'todo'];

    public function scanDiffForKeywords(string $diffContent): array
    {
        $lines = explode(PHP_EOL, $diffContent);
        $files = [];
        $currentFile = null;
        $currentLine = null;

        foreach ($lines as $line) {

            // Skip binary files and extremely long lines
            if (strlen($line) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $line) === true) {
                continue;
            }

            if (preg_match('/^\+\+\+ b\/(.+)/', $line, $matches) === true) {
                $currentFile = $matches[1];
            }

            if (preg_match('/^@@ -\d+,\d+ \+(\d+),\d+ @@/', $line, $matches) === true) {
                $currentLine = $matches[1];
            }

            if (strpos($line, '+') === 0) {
                $currentLine++;
            }

            if (
                $currentFile !== null &&
                $currentLine !== null &&
                preg_match('/^\+(.*)/', $line, $matches) === true
            ) {
                $result = $this->parseCommentLine($matches[1]);
                if ($result !== null) {
                    $files[$currentFile][] = "line: {$currentLine} - {$result['category']}: {$result['description']}";
                }
            }
        }

        return $files;
    }

    private function parseCommentLine(string $line): ?array
    {
        foreach (self::COMMENT_MARKERS as $marker) {
            if (($pos = stripos($line, $marker)) !== false) {
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

    public function generateReport(array $files): string
    {
        if (empty($files) === true) {
            return "No 'bug', 'fixme' or 'todo' comments found in the pull request.";
        }
        $reportLines = ["Found the following comments with 'bug', 'fixme', or 'todo':"];

        foreach ($files as $file => $lines) {
            $reportLines[] = "\nFile: {$file}";
            foreach ($lines as $line) {
                $reportLines[] = " - {$line}";
            }
        }

        return implode("\n", $reportLines);
    }
}
