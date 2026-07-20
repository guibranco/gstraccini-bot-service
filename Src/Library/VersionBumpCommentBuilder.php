<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Builds the actionable comment asking a maintainer to choose a version bump
 * for a feature-looking pull request, and the completion note appended once
 * a choice has been applied.
 */
class VersionBumpCommentBuilder
{
    public const MARKER = "<!-- gstraccini-bot:semver-decision -->";
    public const COMPLETION_MARKER = "<!-- gstraccini-bot:semver-decision:applied -->";
    public const CHECKBOX_MAJOR = "Major version bump (`+semver: major`, breaking change)";
    public const CHECKBOX_MINOR = "Minor version bump (`+semver: minor`)";
    public const CHECKBOX_NONE = "No version bump";

    public function build(string $pullRequestTitle): string
    {
        $body = self::MARKER . "\n";
        $body .= "### Version bump needed\n\n";
        $body .= "\"{$pullRequestTitle}\" looks like a **feature** change (based on its title, branch name, or labels), ";
        $body .= "but I couldn't find a GitVersion `+semver` directive in its commits.\n\n";
        $body .= "Please choose how this should affect the version:\n\n";
        $body .= "- [ ] " . self::CHECKBOX_MAJOR . "\n";
        $body .= "- [ ] " . self::CHECKBOX_MINOR . "\n";
        $body .= "- [ ] " . self::CHECKBOX_NONE . "\n\n";
        $body .= "Check one box to continue. I'll push a commit with the appropriate `+semver` tag " .
            "(or unblock the check directly, for no version bump) once you do.\n\n";
        $body .= "This is part of the overall **GStraccini Checks: Pull Request** check run, " .
            "which will stay in progress until one option is selected.\n";

        return $body;
    }

    public function buildCompletion(string $choiceLabel, ?string $commitSha = null): string
    {
        $completion = "\n\n" . self::COMPLETION_MARKER . "\n";
        $completion .= "✅ {$choiceLabel} applied.\n";
        if ($commitSha !== null) {
            $completion .= "\nCommit: `{$commitSha}`\n";
        }

        return $completion;
    }
}
