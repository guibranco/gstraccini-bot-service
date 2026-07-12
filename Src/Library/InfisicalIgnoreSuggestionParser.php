<?php

namespace GuiBranco\GStracciniBot\Library;

class InfisicalIgnoreSuggestionParser
{
    public function parse(string $commentBody): ?array
    {
        $matched = preg_match(
            '/```suggestion:\.infisicalignore\r?\n(.*?)\r?\n```/s',
            $commentBody,
            $matches
        );

        if (!$matched) {
            return null;
        }

        $lines = preg_split('/\r?\n/', trim($matches[1]));
        $lines = array_values(array_filter(array_map('trim', $lines), function ($line) {
            return $line !== '';
        }));

        if (empty($lines)) {
            return null;
        }

        return $lines;
    }
}
