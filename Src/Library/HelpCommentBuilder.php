<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Builds the `@bot help` comment and handles its checkboxes: every parameterless
 * command gets a checkbox, and ticking one triggers that command. Once a command
 * has been triggered, the comment is rewritten so no checkbox is left to tick.
 */
class HelpCommentBuilder
{
    public const MARKER = "<!-- gstraccini-bot:help -->";
    public const COMPLETION_MARKER = "<!-- gstraccini-bot:help:triggered -->";


    /**
     * Builds the help comment listing every command.
     *
     * @param string $botName  The bot name (without the `[bot]` suffix).
     * @param array  $commands The commands from the bot configuration.
     *
     * @return string
     */
    public function build(string $botName, array $commands): string
    {
        $helpComment = self::MARKER . "\r\n";
        $helpComment .= "That's what I can do :neckbeard::\r\n";
        foreach ($commands as $command) {
            $parameters = "";
            $parametersHelp = "";
            $prefix = "[ ] ";
            $inDevelopment = isset($command->dev) && $command->dev
                ? " :warning: (In development, it may not work as expected!)"
                : "";
            if (isset($command->parameters)) {
                $prefix = "";
                foreach ($command->parameters as $parameter) {
                    $parameters .= " <" . $parameter->parameter . ">";
                    $parametersHelp .= "\t- `" . $parameter->parameter . "` - `[" .
                        ($parameter->required ? "required" : "optional") . "]` " .
                        $parameter->description . "\r\n";
                }
            }

            $helpComment .= "- {$prefix}`@{$botName} {$command->command}{$parameters}` - ";
            $helpComment .= $command->description . $inDevelopment . "\r\n";
            $helpComment .= $parametersHelp;
        }

        $helpComment .= "\n\nMultiple commands can be issued simultaneously. " .
            "Just respect each command pattern (with bot name prefix + command).\n\n" .
            "> [!NOTE]\n" .
            "> \n" .
            "> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.\r\n" .
            "\n\n" .
            "> [!TIP]\n" .
            "> \n" .
            "> You can tick (✅) one item from the above list, and it will be triggered! " .
            "(Only parameterless commands). Once one is ticked, the other checkboxes are disabled.\n";

        return $helpComment;
    }


    /**
     * Finds the command whose checkbox is ticked in the help comment.
     *
     * @param string $body    The help comment body.
     * @param string $botName The bot name (without the `[bot]` suffix).
     *
     * @return string|null The ticked command (e.g. `update snapshot`), or null when none is ticked.
     */
    public function findTickedCommand(string $body, string $botName): ?string
    {
        $pattern = "/^-\s\[[xX]\]\s`@" . preg_quote($botName, "/") . "\s([^`]+)`/m";
        if (preg_match($pattern, $body, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }


    /**
     * Rewrites the help comment once a command has been triggered: the ticked
     * command is marked as done and every checkbox is removed, so nothing else
     * can be ticked.
     *
     * @param string $body    The help comment body.
     * @param string $botName The bot name (without the `[bot]` suffix).
     * @param string $command The triggered command.
     * @param string $user    Who ticked the command.
     *
     * @return string
     */
    public function disableCheckboxes(string $body, string $botName, string $command, string $user): string
    {
        $ticked = "/^-\s\[[xX]\]\s(`@" . preg_quote($botName, "/") . "\s" . preg_quote($command, "/") . "`)/m";
        $body = preg_replace($ticked, "- ✅ $1", $body, 1);
        $body = preg_replace("/^-\s\[[ xX]\]\s/m", "- ", $body);

        $completion = "\n\n" . self::COMPLETION_MARKER . "\n";
        $completion .= "▶️ `@{$botName} {$command}` triggered by @{$user}. " .
            "Comment `@{$botName} help` to get a new list.\n";

        return $body . $completion;
    }


    /**
     * Clears every ticked checkbox, for a tick that can't be acted on
     * (e.g. made by someone who isn't a collaborator).
     *
     * @param string $body The help comment body.
     *
     * @return string
     */
    public function untickCheckboxes(string $body): string
    {
        return preg_replace("/^-\s\[[xX]\]\s/m", "- [ ] ", $body);
    }
}
