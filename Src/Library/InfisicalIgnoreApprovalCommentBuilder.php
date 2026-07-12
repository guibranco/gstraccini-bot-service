<?php

namespace GuiBranco\GStracciniBot\Library;

class InfisicalIgnoreApprovalCommentBuilder
{
    public const MARKER_PREFIX = "<!-- gstraccini-bot:infisicalignore:";
    public const CHECKBOX_LABEL = "Apply this suggestion";
    public const COMPLETION_MARKER = "<!-- gstraccini-bot:infisicalignore:applied -->";

    public function marker(int $originalCommentId): string
    {
        return self::MARKER_PREFIX . $originalCommentId . " -->";
    }

    public function build(string $owner, string $repo, int $prNumber, int $originalCommentId): string
    {
        $permalink = "https://github.com/{$owner}/{$repo}/pull/{$prNumber}#issuecomment-{$originalCommentId}";

        $body = $this->marker($originalCommentId) . "\n";
        $body .= "### Apply `.infisicalignore` update\n\n";
        $body .= "> Suggested by @github-actions[bot]\n";
        $body .= ">\n";
        $body .= "> {$permalink}\n\n";
        $body .= "The workflow detected a suggested update for `.infisicalignore`.\n\n";
        $body .= "- [ ] " . self::CHECKBOX_LABEL . "\n\n";
        $body .= "Once this checkbox is checked, GStraccini Bot will automatically append the suggested entries " .
            "to `.infisicalignore` and commit the change to this pull request.\n";

        return $body;
    }

    public function buildCompletion(string $commitSha, string $commitMessage): string
    {
        return "\n\n" . self::COMPLETION_MARKER . "\n" .
            "✅ Suggestion successfully applied.\n\n" .
            "Commit: `{$commitSha}` {$commitMessage}\n";
    }
}
