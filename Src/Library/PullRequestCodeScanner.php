<?php

namespace GuiBranco\GStracciniBot\Library;

class PullRequestCodeScanner
{
    private const COMMENT_MARKERS = ['//', '#', '%', ';', '--', '<!--', '/*', '*'];
    private const KEYWORDS = ['bug', 'fixme', 'todo'];

    /**
     * File extensions treated as source code for TODO/FIXME/BUG scanning.
     * Documentation/text formats (.md, .txt, .rst, ...) are intentionally excluded
     * since keyword-like words can appear there as ordinary text (e.g. PT-BR "todo").
     */
    private const CODE_FILE_EXTENSIONS = [
        'c', 'cc', 'cpp', 'cxx', 'h', 'hpp',
        'cs', 'go', 'java', 'js', 'jsx', 'kt',
        'php', 'py', 'rb', 'rs', 'scala', 'sh',
        'swift', 'ts', 'tsx'
    ];

    public function scanDiffForKeywords(string $diffContent): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $diffContent));
        $files = [];
        $currentFile = null;
        $currentFileIsCode = false;
        $currentLine = null;

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            // Skip binary files and extremely long lines
            if (strlen($line) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $line)) {
                continue;
            }

            if (preg_match('/^\+\+\+ b\/(.+)/', $line, $matches)) {
                $currentFile = $matches[1];
                $currentFileIsCode = $this->isCodeFile($currentFile);
            }

            if (preg_match('/^@@ -\d+,\d+ \+(\d+),\d+ @@/', $line, $matches)) {
                $currentLine = $matches[1];
            }

            if (strpos($line, '+') === 0) {
                $currentLine++;
            }

            if (
                $currentFile !== null &&
                $currentFileIsCode &&
                $currentLine !== null &&
                preg_match('/^\+(.*)/', $line, $matches)
            ) {
                $result = $this->parseCommentLine($matches[1]);
                if ($result !== null) {
                    $files[$currentFile][] = "line: {$currentLine} - {$result['category']}: {$result['description']}";
                }
            }
        }

        return $files;
    }

    private function isCodeFile(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return in_array($extension, self::CODE_FILE_EXTENSIONS, true);
    }

    private function parseCommentLine(string $line): ?array
    {
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
