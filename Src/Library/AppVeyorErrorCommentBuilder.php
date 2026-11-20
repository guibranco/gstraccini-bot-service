<?php

namespace GuiBranco\GStracciniBot\Library;

use stdClass;

/**
 * Turns a failed AppVeyor API response into a readable pull request comment.
 *
 * AppVeyor wraps its errors as {"message": "..."}. When the failure comes from
 * AppVeyor calling GitHub on our behalf, that message also embeds GitHub's own
 * response ("...returned status code 401: Unauthorized\nFull response body:\n{...}").
 * Posting the raw body leaves escaped newlines and nested JSON in the comment, so
 * this builder unwraps both layers and adds a hint for the failures it can explain.
 */
class AppVeyorErrorCommentBuilder
{
    /** What AppVeyor writes between its own message and the upstream response. */
    private const FULL_RESPONSE_SEPARATOR = "Full response body:";

    /** Line break used in GitHub comments. */
    private const EOL = "\r\n";

    /** Longest AppVeyor message quoted in the comment. */
    private const SUMMARY_LIMIT = 2000;

    /** Longest raw body kept in the comment, well under GitHub's 65,536 characters. */
    private const RAW_LIMIT = 10000;


    /**
     * Builds the comment for a failed AppVeyor request.
     *
     * @param string  $action     What was attempted, e.g. "build" or "registration".
     * @param integer $statusCode HTTP status code AppVeyor answered with.
     * @param string  $body       Raw response body from AppVeyor.
     *
     * @return string Markdown comment body.
     */
    public function build(string $action, int $statusCode, string $body): string
    {
        $eol     = self::EOL;
        $message = $this->extractMessage($body);
        $parts   = $this->splitInnerResponse($message);
        $inner   = $this->parseInnerResponse($parts->inner);

        $comment = "AppVeyor {$action} failed: :x:{$eol}{$eol}**AppVeyor response:** HTTP {$statusCode}{$eol}{$eol}";

        if ($parts->summary !== "") {
            $summary  = $this->truncate($parts->summary, self::SUMMARY_LIMIT);
            $quoted   = $this->quote($this->escapeFence($summary));
            $comment .= "{$quoted}{$eol}{$eol}";
        }

        if ($inner !== null) {
            $comment .= "{$this->describeInner($inner)}{$eol}{$eol}";
        }

        $hint = $this->hint($statusCode, $parts->summary, $inner);
        if ($hint !== null) {
            $comment .= ":bulb: {$hint}{$eol}{$eol}";
        }

        $raw = $this->escapeFence($this->truncate($body, self::RAW_LIMIT));
        if ($body === "") {
            $raw = "(empty response body)";
        }

        $comment .= "<details>{$eol}<summary>Raw response</summary>{$eol}{$eol}";
        $comment .= "```{$eol}{$raw}{$eol}```{$eol}{$eol}</details>{$eol}";

        return $comment;

    }//end build()


    /**
     * Returns AppVeyor's "message" field when the body is its JSON error envelope,
     * otherwise the body itself.
     *
     * @param string $body Raw response body from AppVeyor.
     *
     * @return string
     */
    private function extractMessage(string $body): string
    {
        $decoded = json_decode($body);
        if (is_object($decoded) === true && isset($decoded->message) === true && is_string($decoded->message) === true) {
            return trim($decoded->message);
        }

        return trim($body);

    }//end extractMessage()


    /**
     * Splits "<summary>\nFull response body:\n<inner>" into its two parts.
     *
     * @param string $message AppVeyor's error message.
     *
     * @return stdClass With "summary" (string) and "inner" (string or null).
     */
    private function splitInnerResponse(string $message): stdClass
    {
        $parts          = new stdClass();
        $parts->summary = $message;
        $parts->inner   = null;

        $position = strpos($message, self::FULL_RESPONSE_SEPARATOR);
        if ($position === false) {
            return $parts;
        }

        $innerStart     = ($position + strlen(self::FULL_RESPONSE_SEPARATOR));
        $innerBody      = trim(substr($message, $innerStart));
        $parts->summary = trim(substr($message, 0, $position));
        if ($innerBody !== "") {
            $parts->inner = $innerBody;
        }

        return $parts;

    }//end splitInnerResponse()


    /**
     * Reads the status, message and documentation link of the upstream response.
     *
     * @param string|null $innerBody The upstream response embedded in AppVeyor's message.
     *
     * @return stdClass|null With "status", "message" and "docs", each possibly null.
     */
    private function parseInnerResponse(?string $innerBody): ?stdClass
    {
        if ($innerBody === null) {
            return null;
        }

        $inner          = new stdClass();
        $inner->status  = null;
        $inner->message = $this->singleLine($innerBody);
        $inner->docs    = null;

        $decoded = json_decode($innerBody);
        if (is_object($decoded) === false) {
            return $inner;
        }

        $inner->message = $this->getStringField($decoded, "message");
        $inner->docs    = $this->getStringField($decoded, "documentation_url");
        if (isset($decoded->status) === true && is_numeric($decoded->status) === true) {
            $inner->status = (int) $decoded->status;
        }

        return $inner;

    }//end parseInnerResponse()


    /**
     * Reads a string property of a decoded JSON object.
     *
     * @param object $decoded The decoded JSON object.
     * @param string $name    The property name.
     *
     * @return string|null The value, or null when it is missing or not a string.
     */
    private function getStringField(object $decoded, string $name): ?string
    {
        if (isset($decoded->$name) === true && is_string($decoded->$name) === true) {
            return $decoded->$name;
        }

        return null;

    }//end getStringField()


    /**
     * Describes the upstream response on one line.
     *
     * @param stdClass $inner The parsed upstream response.
     *
     * @return string
     */
    private function describeInner(stdClass $inner): string
    {
        $line = "**Upstream response:**";
        if ($inner->status !== null) {
            $line .= " HTTP {$inner->status}";
        }

        if ($inner->message !== null) {
            $message = addcslashes($this->truncate($inner->message, self::SUMMARY_LIMIT), "\\`*_[]<>|");
            $line   .= " - {$message}";
        }

        $isSafeLink = preg_match("#^https://[^\\s()<>\\[\\]]+$#", ($inner->docs ?? ""));
        if ($isSafeLink === 1) {
            $line .= " ([docs]({$inner->docs}))";
        }

        return $line;

    }//end describeInner()


    /**
     * Explains the failures whose cause is known: GitHub refusing AppVeyor first, then AppVeyor
     * refusing the bot.
     *
     * @param integer       $statusCode HTTP status code AppVeyor answered with.
     * @param string        $summary    AppVeyor's message, without the upstream response.
     * @param stdClass|null $inner      The parsed upstream response.
     *
     * @return string|null The hint, or null when there is none.
     */
    private function hint(int $statusCode, string $summary, ?stdClass $inner): ?string
    {
        $hints = new stdClass();
        $hints->{"github-401"} = "AppVeyor could not authenticate to GitHub. Its GitHub authorization for this account has probably expired or been revoked - reconnect GitHub in the AppVeyor account settings and run the command again.";
        $hints->{"github-403"} = "GitHub refused AppVeyor's request. AppVeyor's GitHub account may not have access to this repository, or GitHub is rate-limiting it - check the upstream message above.";
        $hints->{"401"}        = "AppVeyor rejected the bot's API key. Check the AppVeyor API key configured for the bot.";
        $hints->{"403"}        = "AppVeyor denied the request. The bot's API key may not have access to this project or account.";
        $hints->{"404"}        = "AppVeyor could not find the project, or the bot's API key cannot see it. Make sure the repository is registered in AppVeyor (`appveyor register`) under the bot's account.";

        $hint = null;
        if (stripos($summary, "GitHub") !== false) {
            $upstreamStatus = $this->getUpstreamStatus($summary, $inner);
            $hint           = ($hints->{"github-{$upstreamStatus}"} ?? null);
        }

        if ($hint === null) {
            $hint = ($hints->{(string) $statusCode} ?? null);
        }

        return $hint;

    }//end hint()


    /**
     * Finds the HTTP status of the upstream response, from its body or from AppVeyor's message.
     *
     * @param string        $summary AppVeyor's message, without the upstream response.
     * @param stdClass|null $inner   The parsed upstream response.
     *
     * @return integer|null
     */
    private function getUpstreamStatus(string $summary, ?stdClass $inner): ?int
    {
        if ($inner !== null && $inner->status !== null) {
            return $inner->status;
        }

        if (preg_match("/returned status code (\d{3})/", $summary, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;

    }//end getUpstreamStatus()


    /**
     * Quotes every line of a text as Markdown.
     *
     * @param string $text The text to quote.
     *
     * @return string
     */
    private function quote(string $text): string
    {
        $lines  = preg_split("/\r\n|\r|\n/", $text);
        $quoted = array_map(fn ($line) => "> {$line}", $lines);

        return implode(self::EOL, $quoted);

    }//end quote()


    /**
     * Collapses every run of whitespace into a single space.
     *
     * @param string $text The text to collapse.
     *
     * @return string
     */
    private function singleLine(string $text): string
    {
        return trim(preg_replace("/\s+/", " ", $text));

    }//end singleLine()


    /**
     * Keeps a text from opening or closing a code fence: a zero-width space goes between every
     * two adjacent backticks, so no run of three or more is left.
     *
     * @param string $text The text to escape.
     *
     * @return string
     */
    private function escapeFence(string $text): string
    {
        return preg_replace("/(?<=`)(?=`)/u", "\u{200B}", $text);

    }//end escapeFence()


    /**
     * Shortens a text to a number of characters, saying how much was left out.
     *
     * @param string  $text  The text to shorten.
     * @param integer $limit The most characters to keep.
     *
     * @return string
     */
    private function truncate(string $text, int $limit): string
    {
        $length = mb_strlen($text);
        if ($length <= $limit) {
            return $text;
        }

        $omitted = ($length - $limit);
        $kept    = mb_substr($text, 0, $limit);

        return "{$kept}... ({$omitted} more characters)";

    }//end truncate()


}//end class
