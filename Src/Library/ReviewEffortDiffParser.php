<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Reads a unified diff, one entry per file, for the review effort: the path,
 * kind and status of each file, its added and deleted lines, its hunks and
 * the branching its added lines bring.
 */
class ReviewEffortDiffParser
{
    /** Branching tokens counted in the added lines of code files. */
    private const BRANCHING_PATTERN = '/\b(if|elseif|elif|for|foreach|while|case|catch|except|when)\b|&&|\|\|/';

    /** Lines that are comments only, whose words aren't branching. */
    private const COMMENT_PATTERN = '/^\s*(\/\/|#|\*|\/\*|--|<!--)/';

    /**
     * Reads the paths of the "diff --git" lines.
     *
     * @var PullRequestSizeLabeler
     */
    private PullRequestSizeLabeler $sizeLabeler;

    /**
     * Sets the kind of each file.
     *
     * @var ReviewEffortFileClassifier
     */
    private ReviewEffortFileClassifier $classifier;


    /**
     * Creates the parser.
     */
    public function __construct()
    {
        $this->sizeLabeler = new PullRequestSizeLabeler();
        $this->classifier  = new ReviewEffortFileClassifier();

    }//end __construct()


    /**
     * The files of a diff.
     *
     * @param string $diff The pull request diff (application/vnd.github.v3.diff).
     *
     * @return array<int, array<string, mixed>> One entry per file whose path can be read.
     */
    public function parse(string $diff): array
    {
        $files = [];
        foreach ($this->splitFiles($diff) as $lines) {
            $file = $this->parseFile($lines);
            if ($file !== null) {
                $files[] = $file;
            }
        }

        return $files;

    }//end parse()


    /**
     * Splits a diff into the lines of each file, from its "diff --git" line.
     *
     * @param string $diff The unified diff.
     *
     * @return array<int, string[]>
     */
    private function splitFiles(string $diff): array
    {
        $files = [];
        $index = -1;
        foreach (explode("\n", str_replace("\r\n", "\n", $diff)) as $line) {
            if (str_starts_with($line, "diff --git ") === true) {
                $index++;
                $files[$index] = [];
            }

            if ($index >= 0) {
                $files[$index][] = $line;
            }
        }

        return $files;

    }//end splitFiles()


    /**
     * Reads one file of the diff.
     *
     * @param string[] $lines The lines of the file, from its "diff --git" line.
     *
     * @return array<string, mixed>|null The file, or null when its path can't be read.
     */
    private function parseFile(array $lines): ?array
    {
        $path = $this->sizeLabeler->destinationPath($lines[0]);
        if ($path === null) {
            return null;
        }

        $file     = $this->newFile($path);
        $isCode   = $this->classifier->isCode($file["kind"]);
        $inHeader = true;
        foreach (array_slice($lines, 1) as $line) {
            if (str_starts_with($line, "@@") === true) {
                $inHeader = false;
                $file["hunks"]++;
                continue;
            }

            if ($inHeader === true) {
                $file["status"] = $this->statusFromHeader($line, $file["status"]);
                continue;
            }

            $this->countLine($file, $line, $isCode);
        }

        return $file;

    }//end parseFile()


    /**
     * A file with nothing counted yet.
     *
     * @param string $path The file path in the repository.
     *
     * @return array<string, mixed>
     */
    private function newFile(string $path): array
    {
        $file = [];
        $file["path"]       = $path;
        $file["kind"]       = $this->classifier->kindOf($path);
        $file["status"]     = "modified";
        $file["additions"]  = 0;
        $file["deletions"]  = 0;
        $file["hunks"]      = 0;
        $file["complexity"] = 0;
        $file["risky"]      = $this->classifier->isRisky($path);

        return $file;

    }//end newFile()


    /**
     * Counts one line of a hunk.
     *
     * @param array<string, mixed> $file   The file being read.
     * @param string               $line   The line.
     * @param boolean              $isCode Whether the file is code, scanned for branching.
     *
     * @return void
     */
    private function countLine(array &$file, string $line, bool $isCode): void
    {
        if (str_starts_with($line, "-") === true) {
            $file["deletions"]++;
            return;
        }

        if (str_starts_with($line, "+") === false) {
            return;
        }

        $file["additions"]++;
        if ($isCode === true) {
            $file["complexity"] += $this->branchingIn(substr($line, 1));
        }

    }//end countLine()


    /**
     * The branching tokens of an added line, none for a comment line.
     *
     * @param string $code The added line, without its "+".
     *
     * @return integer
     */
    private function branchingIn(string $code): int
    {
        if (preg_match(self::COMMENT_PATTERN, $code) === 1) {
            return 0;
        }

        return (int) preg_match_all(self::BRANCHING_PATTERN, $code);

    }//end branchingIn()


    /**
     * The status a header line of a file sets, or the current status.
     *
     * @param string $line    A header line ("new file mode", "rename from", "Binary files ...").
     * @param string $current The status so far.
     *
     * @return string added, deleted, renamed, binary or modified.
     */
    private function statusFromHeader(string $line, string $current): string
    {
        foreach ($this->headerStatuses() as $prefix => $status) {
            if (str_starts_with($line, $prefix) === true) {
                return $status;
            }
        }

        return $current;

    }//end statusFromHeader()


    /**
     * The status set by the start of each header line.
     *
     * @return array<string, string>
     */
    private function headerStatuses(): array
    {
        $statuses = [];
        $statuses["new file mode"]     = "added";
        $statuses["deleted file mode"] = "deleted";
        $statuses["rename from"]       = "renamed";
        $statuses["Binary files"]      = "binary";
        $statuses["GIT binary patch"]  = "binary";

        return $statuses;

    }//end headerStatuses()


}//end class
