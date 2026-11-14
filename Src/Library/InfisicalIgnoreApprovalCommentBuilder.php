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

    /**
     * Builds the approval comment for an Infisical `.infisicalignore` suggestion.
     *
     * @param string $owner             Repository owner.
     * @param string $repo              Repository name.
     * @param int    $prNumber          Pull request number.
     * @param int    $originalCommentId Id of the comment or review holding the suggestion.
     * @param bool   $isReview          Whether the suggestion was posted as a pull request review.
     *
     * @return string
     */
    public function build(string $owner, string $repo, int $prNumber, int $originalCommentId, bool $isReview): string
    {
        $anchor = "issuecomment";
        if ($isReview === true) {
            $anchor = "pullrequestreview";
        }
        $permalink = "https://github.com/{$owner}/{$repo}/pull/{$prNumber}#{$anchor}-{$originalCommentId}";

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
