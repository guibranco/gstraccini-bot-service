<?php

namespace GuiBranco\GStracciniBot\Library;

class InfisicalIgnoreFileUpdater
{
    public function merge(?string $existingContent, array $newLines): string
    {
        $existingLines = [];
        if ($existingContent !== null && trim($existingContent) !== '') {
            $existingLines = preg_split('/\r?\n/', rtrim($existingContent, "\r\n"));
        }

        $existingTrimmed = array_map('trim', $existingLines);

        $linesToAppend = [];
        foreach ($newLines as $newLine) {
            $newLine = trim($newLine);
            if ($newLine === '') {
                continue;
            }
            if (in_array($newLine, $existingTrimmed, true) || in_array($newLine, $linesToAppend, true)) {
                continue;
            }
            $linesToAppend[] = $newLine;
        }

        $mergedLines = array_merge($existingLines, $linesToAppend);

        return implode("\n", $mergedLines) . "\n";
    }
}
