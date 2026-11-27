<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Builds the comment asking whether to bump the major version of a pull
 * request whose description says it introduces a breaking change, reads the
 * box ticked on it, and builds the completion note appended once applied.
 */
class BreakingChangeCommentBuilder
{
    public const MARKER            = "<!-- gstraccini-bot:breaking-change -->";
    public const COMPLETION_MARKER = "<!-- gstraccini-bot:breaking-change:applied -->";
    public const CHECKBOX_YES      = "Yes, bump the major version";
    public const CHECKBOX_NO       = "No, keep the current version";


    /**
     * The comment asking for the major version bump.
     *
     * @param string             $pullRequestTitle The pull request title.
     * @param BreakingChangePlan $plan             What the major version bump would do.
     *
     * @return string
     */
    public function build(string $pullRequestTitle, BreakingChangePlan $plan): string
    {
        $body  = sprintf("%s\n", self::MARKER);
        $body .= "### ☢️ Breaking change\n\n";
        $body .= "\"{$pullRequestTitle}\" says it **introduces a breaking change**, so I added the `breaking-changes` label.\n\n";
        $body .= "Do you want to bump to the next **major** version? If you do, I will:\n\n";
        $body .= $this->describeActions($plan);
        $body .= sprintf("\n- [ ] %s\n- [ ] %s\n\n", self::CHECKBOX_YES, self::CHECKBOX_NO);
        $body .= "Check one box to continue.\n";

        return $body;

    }//end build()


    /**
     * The box ticked on the comment.
     *
     * @param string $body The comment body.
     *
     * @return string|null "yes", "no", or null when no box (or both) is ticked.
     */
    public function extractChoice(string $body): ?string
    {
        $yesTicked = $this->isTicked($body, self::CHECKBOX_YES);
        $noTicked  = $this->isTicked($body, self::CHECKBOX_NO);
        if ($yesTicked === $noTicked) {
            return null;
        }

        if ($yesTicked === true) {
            return "yes";
        }

        return "no";

    }//end extractChoice()


    /**
     * The note appended to the comment once the choice was applied.
     *
     * @param string[]    $done      What was done, one line each.
     * @param string|null $commitSha The commit pushed, if any.
     *
     * @return string
     */
    public function buildCompletion(array $done, ?string $commitSha): string
    {
        $completion = sprintf("\n\n%s\n", self::COMPLETION_MARKER);
        foreach ($done as $line) {
            $completion .= "✅ {$line}\n";
        }

        if ($commitSha !== null) {
            $completion .= "\nCommit: `{$commitSha}`\n";
        }

        return $completion;

    }//end buildCompletion()


    /**
     * The list of what the major version bump would do.
     *
     * @param BreakingChangePlan $plan What the major version bump needs.
     *
     * @return string
     */
    private function describeActions(BreakingChangePlan $plan): string
    {
        $actions = "";
        if ($plan->needsAppVeyorBump() === true) {
            $actions .= "- update the version in `{$plan->appVeyorPath}` from `{$plan->appVeyorFrom}` to `{$plan->appVeyorTo}`;\n";
            $actions .= "- reset the AppVeyor build number;\n";
        }

        if ($plan->needsGitVersionBump() === false) {
            return $actions;
        }

        if ($plan->needsAppVeyorBump() === true) {
            return "{$actions}- add the GitVersion `+semver: major` directive to that commit.\n";
        }

        return "{$actions}- push an empty commit with the GitVersion `+semver: major` directive.\n";

    }//end describeActions()


    /**
     * Whether a checkbox of the comment is ticked.
     *
     * @param string $body  The comment body.
     * @param string $label The checkbox label.
     *
     * @return boolean
     */
    private function isTicked(string $body, string $label): bool
    {
        $pattern = sprintf("/-\s\[[xX]\]\s%s/", preg_quote($label, "/"));

        return preg_match($pattern, $body) === 1;

    }//end isTicked()


}//end class
