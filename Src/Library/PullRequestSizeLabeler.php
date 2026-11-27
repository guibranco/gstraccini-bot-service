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
     * The prefix every size label starts with; any label with it is a size label.
     */
    public const SIZE_LABEL_PREFIX = "size/";

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
        $counting     = false;
        $inHeader     = false;

        foreach (explode("\n", str_replace("\r\n", "\n", $diff)) as $line) {
            if (str_starts_with($line, "diff --git ")) {
                $inHeader = true;
                $counting = $this->startFile($line, $ignoredFiles);
                continue;
            }

            if ($inHeader === true) {
                // The file header ("index", "---", "+++", mode lines) ends at the first hunk.
                $inHeader = str_starts_with($line, "@@") === false;
                continue;
            }

            if ($counting === true) {
                $additions += (int) str_starts_with($line, "+");
                $deletions += (int) str_starts_with($line, "-");
            }
        }

        return [
            "additions"    => $additions,
            "deletions"    => $deletions,
            "changes"      => ($additions + $deletions),
            "ignoredFiles" => array_values(array_unique($ignoredFiles)),
        ];

    }//end measureDiff()


    /**
     * Reads the file of a "diff --git" line and whether its changes count.
     *
     * @param string   $line          The "diff --git a/<path> b/<path>" line.
     * @param string[] &$ignoredFiles The generated files found so far.
     *
     * @return boolean Whether the lines of the file count toward the size.
     */
    private function startFile(string $line, array &$ignoredFiles): bool
    {
        $path = $this->destinationPath($line);
        if ($path === null) {
            return false;
        }

        if ($this->isIgnored($path) === true) {
            $ignoredFiles[] = $path;
            return false;
        }

        return true;

    }//end startFile()


    /**
     * The destination path of a "diff --git" line, decoding the quoted form
     * git uses for paths with special characters ("b/caf\303\251.php").
     *
     * @param string $line The "diff --git" line.
     *
     * @return string|null The path, or null when the line can't be read.
     */
    public function destinationPath(string $line): ?string
    {
        if (preg_match('/ "b\/((?:[^"\\\\]|\\\\.)*)"$/', $line, $matches) === 1) {
            return stripcslashes($matches[1]);
        }

        if (preg_match('/ b\/(.+)$/', $line, $matches) === 1) {
            return $matches[1];
        }

        return null;

    }//end destinationPath()


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
     * Any label starting with "size/" counts, so a size label not in SIZE_LABELS
     * (from another tool, say) doesn't stay next to the computed one.
     *
     * @param string[] $currentLabels The names of the labels the pull request has.
     * @param string   $target        The size label the pull request should have.
     *
     * @return string[] The stale size labels, with the names as they are on the pull request.
     */
    public function staleLabels(array $currentLabels, string $target): array
    {
        return array_values(
            array_filter(
                $currentLabels,
                fn (string $label) => $this->isSizeLabel($label) && strcasecmp($label, $target) !== 0
            )
        );

    }//end staleLabels()


    /**
     * Whether a label is a size label.
     *
     * @param string $label The label name.
     *
     * @return boolean
     */
    public function isSizeLabel(string $label): bool
    {
        return stripos($label, self::SIZE_LABEL_PREFIX) === 0;

    }//end isSizeLabel()


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
