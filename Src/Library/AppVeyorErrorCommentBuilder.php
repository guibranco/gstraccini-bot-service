<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Turns a failed AppVeyor API response into a readable pull request comment.
 *
 * AppVeyor wraps its errors as {"message": "..."}. When the failure comes from
 * AppVeyor calling GitHub on our behalf, that message also embeds GitHub's own
 * response ("...returned status code 401: Unauthorized\nFull response body:\n{...}").
 * Posting the raw body leaves escaped newlines and nested JSON in the comment, so
 * this builder unwraps both layers and adds a hint for the failures we can explain.
 */
class AppVeyorErrorCommentBuilder
{
    private const FULL_RESPONSE_SEPARATOR = "Full response body:";

    /**
     * @param string $action     What was attempted, e.g. "build" or "registration".
     * @param int    $statusCode HTTP status code AppVeyor answered with.
     * @param string $body       Raw response body from AppVeyor.
     *
     * @return string Markdown comment body.
     */
    public function build(string $action, int $statusCode, string $body): string
    {
        $message = $this->extractMessage($body);
        [$summary, $innerBody] = $this->splitInnerResponse($message);
        $inner = $this->parseInnerResponse($innerBody);

        $comment = "AppVeyor " . $action . " failed: :x:\r\n\r\n";
        $comment .= "**AppVeyor response:** HTTP " . $statusCode . "\r\n\r\n";

        if ($summary !== "") {
            $comment .= $this->quote($this->escapeFence($summary)) . "\r\n\r\n";
        }

        if ($inner !== null) {
            $comment .= "**Upstream response:**";
            if ($inner["status"] !== null) {
                $comment .= " HTTP " . $inner["status"];
            }
            if ($inner["message"] !== null) {
                $comment .= " - " . $inner["message"];
            }
            if ($inner["documentation_url"] !== null) {
                $comment .= " ([docs](" . $inner["documentation_url"] . "))";
            }
            $comment .= "\r\n\r\n";
        }

        $hint = $this->hint($statusCode, $summary, $inner);
        if ($hint !== null) {
            $comment .= ":bulb: " . $hint . "\r\n\r\n";
        }

        $comment .= "<details>\r\n<summary>Raw response</summary>\r\n\r\n";
        $comment .= "```\r\n" . $this->escapeFence($body === "" ? "(empty response body)" : $body) . "\r\n```\r\n\r\n";
        $comment .= "</details>\r\n";

        return $comment;
    }

    /**
     * Returns AppVeyor's "message" field when the body is its JSON error envelope,
     * otherwise the body itself.
     */
    private function extractMessage(string $body): string
    {
        $decoded = json_decode($body);
        if (is_object($decoded) && isset($decoded->message) && is_string($decoded->message)) {
            return trim($decoded->message);
        }

        return trim($body);
    }

    /**
     * Splits "<summary>\nFull response body:\n<inner>" into its two parts.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitInnerResponse(string $message): array
    {
        $position = strpos($message, self::FULL_RESPONSE_SEPARATOR);
        if ($position === false) {
            return [$message, null];
        }

        $summary = trim(substr($message, 0, $position));
        $inner = trim(substr($message, $position + strlen(self::FULL_RESPONSE_SEPARATOR)));

        return [$summary, $inner === "" ? null : $inner];
    }

    /**
     * @return array{status: int|null, message: string|null, documentation_url: string|null}|null
     */
    private function parseInnerResponse(?string $innerBody): ?array
    {
        if ($innerBody === null) {
            return null;
        }

        $decoded = json_decode($innerBody);
        if (!is_object($decoded)) {
            return ["status" => null, "message" => $this->singleLine($innerBody), "documentation_url" => null];
        }

        $status = isset($decoded->status) && is_numeric($decoded->status) ? (int) $decoded->status : null;
        $message = isset($decoded->message) && is_string($decoded->message) ? $decoded->message : null;
        $docs = isset($decoded->documentation_url) && is_string($decoded->documentation_url)
            ? $decoded->documentation_url
            : null;

        return ["status" => $status, "message" => $message, "documentation_url" => $docs];
    }

    private function hint(int $statusCode, string $summary, ?array $inner): ?string
    {
        $innerStatus = $inner["status"] ?? null;
        if ($innerStatus === null && preg_match("/returned status code (\d{3})/", $summary, $matches) === 1) {
            $innerStatus = (int) $matches[1];
        }

        if (stripos($summary, "GitHub") !== false && ($innerStatus === 401 || $innerStatus === 403)) {
            return "AppVeyor could not authenticate to GitHub. Its GitHub authorization for this account " .
                "has probably expired or been revoked - reconnect GitHub in the AppVeyor account settings " .
                "and run the command again.";
        }

        if ($statusCode === 401 || $statusCode === 403) {
            return "AppVeyor rejected the bot's API key. Check the AppVeyor API key configured for the bot.";
        }

        if ($statusCode === 404) {
            return "AppVeyor could not find the project. Make sure the repository is registered in AppVeyor " .
                "(`appveyor register`).";
        }

        return null;
    }

    private function quote(string $text): string
    {
        $lines = preg_split("/\r\n|\r|\n/", $text);

        return implode("\r\n", array_map(fn ($line) => "> " . $line, $lines));
    }

    private function singleLine(string $text): string
    {
        return trim(preg_replace("/\s+/", " ", $text));
    }

    /**
     * Keeps the raw body from closing the surrounding code fence early.
     */
    private function escapeFence(string $text): string
    {
        return str_replace("```", "`\u{200B}``", $text);
    }
}
