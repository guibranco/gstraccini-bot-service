<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Estimates how much effort reviewing a pull request takes, from its diff.
 *
 * Unlike the size label, which counts changed lines, the estimate weighs what
 * changed and how it is spread out, without AI:
 *
 * - each file by its kind (docs and tests are lighter than source, migrations
 *   and infrastructure heavier, generated files are left out);
 * - lines on a square root, so a long but simple change doesn't dominate;
 * - the hunks of each file, as scattered edits are harder than one block;
 * - the branching added (if, for, while, case, catch, &&, ||) in code files;
 * - how many modules and languages the change spans;
 * - a multiplier when sensitive paths (auth, security, migrations, CI) change.
 *
 * Whole-file deletions and pure renames weigh little.
 */
class ReviewEffortEstimator
{
    /** The points per hunk of a file. */
    public const HUNK_POINTS = 0.5;

    /** The points per branching token added in a code file, times the weight of the file. */
    public const BRANCHING_POINTS = 0.3;

    /** The points per extra module touched. */
    public const MODULE_POINTS = 1.5;

    /** The points per extra language touched. */
    public const LANGUAGE_POINTS = 1.0;

    /** The points for a binary file, which has no lines to count. */
    public const BINARY_POINTS = 1.0;

    /** The multiplier when a sensitive path changes. */
    public const RISK_MULTIPLIER = 1.3;

    /** The weight of the deleted lines of a file, against its added lines. */
    public const DELETION_WEIGHT = 0.3;

    /** The weight of the lines of a deleted file. */
    public const DELETED_FILE_WEIGHT = 0.1;

    /** The weight of the lines of a new file, read once, with no before to compare. */
    public const NEW_FILE_WEIGHT = 0.8;

    /** The changed source lines from which a pull request without test changes is flagged. */
    public const MISSING_TESTS_LINES = 10;

    /**
     * Reads the files of the diff.
     *
     * @var ReviewEffortDiffParser
     */
    private ReviewEffortDiffParser $parser;

    /**
     * Weighs the files and tells code from the rest.
     *
     * @var ReviewEffortFileClassifier
     */
    private ReviewEffortFileClassifier $classifier;

    /**
     * Detects the generated files, left out as for the size label.
     *
     * @var PullRequestSizeLabeler
     */
    private PullRequestSizeLabeler $sizeLabeler;


    /**
     * Creates the estimator.
     */
    public function __construct()
    {
        $this->parser      = new ReviewEffortDiffParser();
        $this->classifier  = new ReviewEffortFileClassifier();
        $this->sizeLabeler = new PullRequestSizeLabeler();

    }//end __construct()


    /**
     * Estimates the review effort of a unified diff.
     *
     * @param string $diff The pull request diff (application/vnd.github.v3.diff).
     *
     * @return array<string, mixed> The score, bucket, files (heaviest first), modules, languages,
     *                              riskyFiles, ignoredFiles, hunks, complexity and missingTests.
     */
    public function estimateDiff(string $diff): array
    {
        $summary = $this->emptySummary();
        foreach ($this->parser->parse($diff) as $file) {
            if ($this->sizeLabeler->isGenerated($file["path"]) === true) {
                $summary["ignoredFiles"][] = $file["path"];
                continue;
            }

            $file["score"] = $this->scoreFile($file);
            $this->addFile($summary, $file);
        }

        return $this->finishSummary($summary);

    }//end estimateDiff()


    /**
     * A rough estimate from the pull request's own totals, for when the diff can't be read.
     *
     * @param integer $changedFiles The number of changed files.
     * @param integer $additions    The added lines.
     * @param integer $deletions    The deleted lines.
     *
     * @return array<string, mixed> The score and bucket.
     */
    public function estimateTotals(int $changedFiles, int $additions, int $deletions): array
    {
        $lines = ($additions + (self::DELETION_WEIGHT * $deletions));
        $score = round((sqrt($lines) + (self::HUNK_POINTS * $changedFiles)), 1);

        $estimate           = [];
        $estimate["score"]  = $score;
        $estimate["bucket"] = $this->bucketFor($score);

        return $estimate;

    }//end estimateTotals()


    /**
     * The bucket of a score: the heaviest whose minimum the score reaches.
     *
     * @param float $score The effort score.
     *
     * @return string
     */
    public function bucketFor(float $score): string
    {
        $bucket = "";
        foreach ($this->buckets() as $name => $minimum) {
            if ($score >= $minimum) {
                $bucket = $name;
            }
        }

        return $bucket;

    }//end bucketFor()


    /**
     * The effort buckets, from the lightest, with the minimum score of each.
     *
     * @return array<string, float>
     */
    private function buckets(): array
    {
        $buckets = [];
        $buckets["⚡ Trivial (~5 min)"]   = 0.0;
        $buckets["☕ Light (~15 min)"]    = 5.0;
        $buckets["🧠 Moderate (~30 min)"] = 15.0;
        $buckets["🔍 Heavy (~1 h)"]       = 35.0;
        $buckets["🪓 Very heavy (consider splitting)"] = 70.0;

        return $buckets;

    }//end buckets()


    /**
     * The score of one file.
     *
     * @param array<string, mixed> $file The parsed file.
     *
     * @return float
     */
    private function scoreFile(array $file): float
    {
        if ($file["status"] === "binary") {
            return self::BINARY_POINTS;
        }

        $weight = $this->classifier->weightOf($file["kind"]);
        $lines  = ($file["additions"] + (self::DELETION_WEIGHT * $file["deletions"]));
        $hunks  = $file["hunks"];
        if ($file["status"] === "deleted") {
            $lines = (self::DELETED_FILE_WEIGHT * $file["deletions"]);
            $hunks = 0;
        }

        if ($file["status"] === "added") {
            $lines = (self::NEW_FILE_WEIGHT * $lines);
        }

        $score = (($weight * sqrt($lines)) + (self::HUNK_POINTS * $hunks));
        $score = ($score + (self::BRANCHING_POINTS * $weight * $file["complexity"]));

        return round($score, 2);

    }//end scoreFile()


    /**
     * The summary before any file is added.
     *
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        $summary = [];
        $summary["score"]        = 0.0;
        $summary["files"]        = [];
        $summary["modules"]      = [];
        $summary["languages"]    = [];
        $summary["riskyFiles"]   = [];
        $summary["ignoredFiles"] = [];
        $summary["hunks"]        = 0;
        $summary["complexity"]   = 0;
        $summary["sourceLines"]  = 0;
        $summary["testsChanged"] = false;

        return $summary;

    }//end emptySummary()


    /**
     * Adds a counted file to the summary.
     *
     * @param array<string, mixed> $summary The summary so far.
     * @param array<string, mixed> $file    The scored file.
     *
     * @return void
     */
    private function addFile(array &$summary, array $file): void
    {
        $summary["files"][] = $file;
        $summary["score"]  += $file["score"];
        $summary["hunks"]  += $file["hunks"];
        $summary["complexity"] += $file["complexity"];
        $summary["modules"][$this->moduleOf($file["path"])] = true;

        $extension = strtolower(pathinfo($file["path"], PATHINFO_EXTENSION));
        if ($this->classifier->isCode($file["kind"]) === true && $extension !== "") {
            $summary["languages"][$extension] = true;
        }

        if ($file["risky"] === true) {
            $summary["riskyFiles"][] = $file["path"];
        }

        if ($file["kind"] === ReviewEffortFileClassifier::KIND_TEST) {
            $summary["testsChanged"] = true;
        }

        if ($file["kind"] === ReviewEffortFileClassifier::KIND_SOURCE && $file["status"] !== "deleted") {
            $summary["sourceLines"] += ($file["additions"] + $file["deletions"]);
        }

    }//end addFile()


    /**
     * Adds the spread and the risk to the score of the files, and sorts the files from the heaviest.
     *
     * @param array<string, mixed> $summary The summary with every file added.
     *
     * @return array<string, mixed>
     */
    private function finishSummary(array $summary): array
    {
        $extraModules   = max(0, (count($summary["modules"]) - 1));
        $extraLanguages = max(0, (count($summary["languages"]) - 1));
        $score          = ($summary["score"] + (self::MODULE_POINTS * $extraModules) + (self::LANGUAGE_POINTS * $extraLanguages));
        if (count($summary["riskyFiles"]) > 0) {
            $score = ($score * self::RISK_MULTIPLIER);
        }

        $files = $summary["files"];
        usort($files, static fn (array $first, array $second) => ($second["score"] <=> $first["score"]));

        $estimate                 = [];
        $estimate["score"]        = round($score, 1);
        $estimate["bucket"]       = $this->bucketFor($estimate["score"]);
        $estimate["files"]        = $files;
        $estimate["modules"]      = array_keys($summary["modules"]);
        $estimate["languages"]    = array_keys($summary["languages"]);
        $estimate["riskyFiles"]   = $summary["riskyFiles"];
        $estimate["ignoredFiles"] = array_values(array_unique($summary["ignoredFiles"]));
        $estimate["hunks"]        = $summary["hunks"];
        $estimate["complexity"]   = $summary["complexity"];
        $estimate["missingTests"] = ($summary["testsChanged"] === false && $summary["sourceLines"] >= self::MISSING_TESTS_LINES);

        return $estimate;

    }//end finishSummary()


    /**
     * The module of a file: its directory, up to two levels deep ("(root)" for files at the root).
     *
     * @param string $path The file path in the repository.
     *
     * @return string
     */
    private function moduleOf(string $path): string
    {
        $segments = explode("/", $path);
        array_pop($segments);
        if (count($segments) === 0) {
            return "(root)";
        }

        return implode("/", array_slice($segments, 0, 2));

    }//end moduleOf()


}//end class
