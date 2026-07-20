<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Detects whether a pull request looks like a feature change, and whether
 * it (or its commits) already carries a GitVersion `+semver` directive.
 */
class VersionBumpAnalyzer
{
    private const FEATURE_PATTERN = '/\bfeat(?:ure)?\b/i';
    private const SEMVER_PATTERN = '/\+semver:\s?(major|breaking|minor|feature|patch|fix|none|skip)/i';

    /**
     * @param string $title The pull request title.
     * @param string $branch The pull request head branch name.
     * @param string[] $labels Names of labels applied to the pull request.
     */
    public function looksLikeFeature(string $title, string $branch, array $labels): bool
    {
        if (preg_match(self::FEATURE_PATTERN, $title) || preg_match(self::FEATURE_PATTERN, $branch)) {
            return true;
        }

        foreach ($labels as $label) {
            if (preg_match(self::FEATURE_PATTERN, $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $messages Text to scan for a `+semver:` directive (e.g. the PR title
     *                           plus each commit message in the pull request).
     */
    public function hasSemverDirective(array $messages): bool
    {
        foreach ($messages as $message) {
            if (preg_match(self::SEMVER_PATTERN, $message)) {
                return true;
            }
        }

        return false;
    }
}
