<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Works out the size label of a pull request from its diff.
 *
 * The size is the number of changed lines (additions + deletions), with the
 * same thresholds and label names as pascalgn/size-label-action and the
 * `size` category of config/labels.json, so repositories that used the
 * workflow keep their labels. Unlike the workflow, generated files (lock
 * files, minified bundles, source maps) are left out, as the label
 * descriptions in config/labels.json say.
 */
class PullRequestSizeLabeler
{
    /**
     * The size labels, from the smallest, with the minimum number of changed lines of each.
     *
     * @var array<string, int>
     */
    public const SIZE_LABELS = [
        "size/XS"  => 0,
        "size/S"   => 10,
        "size/M"   => 30,
        "size/L"   => 100,
        "size/XL"  => 500,
        "size/XXL" => 1000,
    ];

    /**
     * Generated files whose changes don't count toward the size (matched against the file path).
     *
     * @var string[]
     */
    private const IGNORED_FILE_PATTERNS = [
        '/(^|\/)(package-lock|npm-shrinkwrap)\.json$/i',
        '/(^|\/)yarn\.lock$/i',
        '/(^|\/)pnpm-lock\.ya?ml$/i',
        '/(^|\/)bun\.lockb?$/i',
        '/(^|\/)composer\.lock$/i',
        '/(^|\/)Gemfile\.lock$/i',
        '/(^|\/)Cargo\.lock$/i',
        '/(^|\/)(poetry|Pipfile|uv|pdm)\.lock$/i',
        '/(^|\/)packages\.lock\.json$/i',
        '/(^|\/)paket\.lock$/i',
        '/(^|\/)go\.sum$/i',
        '/(^|\/)mix\.lock$/i',
        '/(^|\/)pubspec\.lock$/i',
        '/(^|\/)Podfile\.lock$/i',
        '/(^|\/)Package\.resolved$/i',
        '/\.min\.(js|css)$/i',
        '/\.(js|css)\.map$/i',
    ];


    /**
     * Counts the changed lines of a unified diff, skipping the generated files.
     *
     * @param string $diff The pull request diff (application/vnd.github.v3.diff).
     *
     * @return array{additions: int, deletions: int, changes: int, ignoredFiles: string[]}
     */
    public function measureDiff(string $diff): array
    {
        $additions    = 0;
        $deletions    = 0;
        $ignoredFiles = [];
        $currentFile  = null;
        $inHeader     = false;
        $ignored      = false;

        foreach (explode("\n", str_replace("\r\n", "\n", $diff)) as $line) {
            if (str_starts_with($line, "diff --git ")) {
                $inHeader    = true;
                $currentFile = (preg_match('/ b\/(.+)$/', $line, $matches) === 1) ? $matches[1] : null;
                $ignored     = $currentFile !== null && $this->isIgnored($currentFile);
                if ($ignored === true) {
                    $ignoredFiles[] = $currentFile;
                }

                continue;
            }

            if ($inHeader === true) {
                // The file header ("index", "---", "+++", mode lines) ends at the first hunk.
                $inHeader = str_starts_with($line, "@@") === false;
                continue;
            }

            if ($currentFile === null || $ignored === true) {
                continue;
            }

            if (str_starts_with($line, "+")) {
                $additions++;
            } elseif (str_starts_with($line, "-")) {
                $deletions++;
            }
        }//end foreach

        return [
            "additions"    => $additions,
            "deletions"    => $deletions,
            "changes"      => ($additions + $deletions),
            "ignoredFiles" => array_values(array_unique($ignoredFiles)),
        ];

    }//end measureDiff()


    /**
     * The size label for a number of changed lines.
     *
     * @param int $changes The changed lines (additions + deletions).
     *
     * @return string One of the SIZE_LABELS.
     */
    public function labelFor(int $changes): string
    {
        $label = array_key_first(self::SIZE_LABELS);
        foreach (self::SIZE_LABELS as $name => $minimum) {
            if ($changes >= $minimum) {
                $label = $name;
            }
        }

        return $label;

    }//end labelFor()


    /**
     * The size labels of the pull request other than the target one, to be removed.
     *
     * @param string[] $currentLabels The names of the labels the pull request has.
     * @param string   $target        The size label the pull request should have.
     *
     * @return string[] The stale size labels, with the names as they are on the pull request.
     */
    public function staleLabels(array $currentLabels, string $target): array
    {
        $sizeLabels = array_map("strtolower", array_keys(self::SIZE_LABELS));

        return array_values(
            array_filter(
                $currentLabels,
                static fn (string $label) => in_array(strtolower($label), $sizeLabels, true) && strcasecmp($label, $target) !== 0
            )
        );

    }//end staleLabels()


    /**
     * Whether the pull request already has the target size label.
     *
     * @param string[] $currentLabels The names of the labels the pull request has.
     * @param string   $target        The size label the pull request should have.
     *
     * @return boolean
     */
    public function hasLabel(array $currentLabels, string $target): bool
    {
        foreach ($currentLabels as $label) {
            if (strcasecmp($label, $target) === 0) {
                return true;
            }
        }

        return false;

    }//end hasLabel()


    /**
     * Whether a file is generated, so its changes don't count.
     *
     * @param string $path The file path in the repository.
     *
     * @return boolean
     */
    private function isIgnored(string $path): bool
    {
        foreach (self::IGNORED_FILE_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;

    }//end isIgnored()


}//end class
