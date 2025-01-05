<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Class CommandHelper
 *
 * This class provides helper methods for executing and managing commands.
 *
 * @package Library
 */
class CommandHelper
{
    /**
     * Extracts a conclusion from a given comment based on the command, bot name, and metadata.
     *
     * @param string $command The command to be processed.
     * @param string $botName The name of the bot processing the command.
     * @param array $metadata Additional metadata that may influence the conclusion.
     * @param stdClass $comment The comment object from which the conclusion is to be extracted.
     * @return string|null The extracted conclusion, or null if no conclusion can be determined.
     */
    public function getConclusionFromComment(string $command, string $botName, array $metadata, stdClass $comment): ?string
    {
        $validConclusions = array("action_required", "cancelled", "timed_out", "failure", "neutral", "skipped", "stale", "startup_failure", "success");

        preg_match(
            "/@" . $botName . "\srerun\s" . $command . "(?:\s(\w+))?/",
            $comment->CommentBody,
            $matches
        );

        $type = count($matches) === 1 ? "failure" : $matches[1];

        if (!in_array($type, $validConclusions)) {
            doRequestGitHub($metadata["token"], $metadata["reactionUrl"], array("content" => "-1"), "POST");
            $body = $metadata["errorMessages"]["invalidParameter"];
            doRequestGitHub($metadata["token"], $metadata["commentUrl"], array("body" => $body), "POST");
            return null;
        }

        return $type;
    }
}
