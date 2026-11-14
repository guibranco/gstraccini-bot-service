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
     * Builds the approval comment for a suggestion posted as an issue comment.
     *
     * @param string $owner             Repository owner.
     * @param string $repo              Repository name.
     * @param int    $prNumber          Pull request number.
     * @param int    $originalCommentId Id of the issue comment holding the suggestion.
     *
     * @return string
     */
    public function build(string $owner, string $repo, int $prNumber, int $originalCommentId): string
    {
        $permalink = "https://github.com/{$owner}/{$repo}/pull/{$prNumber}#issuecomment-{$originalCommentId}";

        return $this->buildBody($originalCommentId, $permalink);
    }

    /**
     * Builds the approval comment for a suggestion posted as a pull request review,
     * which is how the Infisical secrets check action delivers it.
     *
     * @param string $owner            Repository owner.
     * @param string $repo             Repository name.
     * @param int    $prNumber         Pull request number.
     * @param int    $originalReviewId Id of the pull request review holding the suggestion.
     *
     * @return string
     */
    public function buildForReview(string $owner, string $repo, int $prNumber, int $originalReviewId): string
    {
        $permalink = "https://github.com/{$owner}/{$repo}/pull/{$prNumber}#pullrequestreview-{$originalReviewId}";

        return $this->buildBody($originalReviewId, $permalink);
    }

    /**
     * Builds the approval comment body shared by issue-comment and review suggestions.
     *
     * @param int    $originalCommentId Id of the comment or review holding the suggestion.
     * @param string $permalink         Link to the original suggestion.
     *
     * @return string
     */
    private function buildBody(int $originalCommentId, string $permalink): string
    {
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
