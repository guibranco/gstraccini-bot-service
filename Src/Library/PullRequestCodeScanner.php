<?php

namespace Guibranco\GStracciniBot\Library;

class PullRequestCodeScanner
{
    public function scanDiffForKeywords($diff): array
    {
        $keywords = ['bug', 'todo', 'fixme'];
        $linesWithKeywords = [];
        $diffLines = explode("\n", $diff);

        foreach ($diffLines as $line) {
            if (strpos($line, '+') === 0) {
                $cleanedLine = trim(substr($line, 1));
                if (preg_match('/(\/\/|#|\/\*)/i', $cleanedLine)) {
                    foreach ($keywords as $keyword) {
                        if (stripos($cleanedLine, $keyword) !== false) {
                            $linesWithKeywords[] = $cleanedLine;
                            break;
                        }
                    }
                }
            }
        }

        return $linesWithKeywords;
    }

    public function generateReport(array $foundComments): string
    {
        if (empty($foundComments)) {
            return "No 'bug', 'todo', or 'fixme' comments found in the pull request.";
        }

        $report = "Found the following comments with 'bug', 'todo', or 'fixme':\n";
        foreach ($foundComments as $comment) {
            $report .= "- $comment\n";
        }

        return $report;
    }
}
