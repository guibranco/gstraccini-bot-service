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
    private const IN_DEVELOPMENT = " :warning: (In development, it may not work as expected!)";


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
        $lines   = [self::MARKER];
        $lines[] = "That's what I can do :neckbeard::";
        foreach ($commands as $command) {
            $lines[] = $this->buildCommandLine($botName, $command);
        }

        $lines[] = "";
        $lines[] = "Multiple commands can be issued simultaneously. Just respect each command pattern (with bot name prefix + command).";
        $lines[] = "";
        $lines[] = "> [!NOTE]";
        $lines[] = "> ";
        $lines[] = "> If you aren't allowed to use this bot, a reaction with a thumbs down will be added to your comment.";
        $lines[] = "";
        $lines[] = "> [!TIP]";
        $lines[] = "> ";
        $lines[] = "> You can tick (✅) one item from the above list, and it will be triggered! (Only parameterless commands). Once one is ticked, the other checkboxes are disabled.";

        return sprintf("%s\r\n", implode("\r\n", $lines));

    }//end build()


    /**
     * Builds the list item of one command, with its parameters below it.
     * Only a parameterless command gets a checkbox.
     *
     * @param string $botName The bot name (without the `[bot]` suffix).
     * @param object $command The command from the bot configuration.
     *
     * @return string
     */
    private function buildCommandLine(string $botName, object $command): string
    {
        $suffix = "";
        if (isset($command->dev) === true && $command->dev === true) {
            $suffix = self::IN_DEVELOPMENT;
        }

        if (isset($command->parameters) === false) {
            return "- [ ] `@{$botName} {$command->command}` - {$command->description}{$suffix}";
        }

        $names = "";
        $help  = "";
        foreach ($command->parameters as $parameter) {
            $required = "optional";
            if ($parameter->required === true) {
                $required = "required";
            }

            $names .= " <{$parameter->parameter}>";
            $help  .= "\r\n\t- `{$parameter->parameter}` - `[{$required}]` {$parameter->description}";
        }

        return "- `@{$botName} {$command->command}{$names}` - {$command->description}{$suffix}{$help}";

    }//end buildCommandLine()


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
        $name    = preg_quote($botName, "/");
        $pattern = "/^-\s\[[xX]\]\s`@{$name}\s([^`]+)`/m";
        if (preg_match($pattern, $body, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);

    }//end findTickedCommand()


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
        $name    = preg_quote($botName, "/");
        $quoted  = preg_quote($command, "/");
        $ticked  = "/^-\s\[[xX]\]\s(`@{$name}\s{$quoted}`)/m";
        $body    = preg_replace($ticked, "- ✅ $1", $body, 1);
        $body    = preg_replace("/^-\s\[[ xX]\]\s/m", "- ", $body);
        $marker  = self::COMPLETION_MARKER;
        $trigger = "▶️ `@{$botName} {$command}` triggered by @{$user}. Comment `@{$botName} help` to get a new list.";

        return "{$body}\n\n{$marker}\n{$trigger}\n";

    }//end disableCheckboxes()


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

    }//end untickCheckboxes()


}//end class
