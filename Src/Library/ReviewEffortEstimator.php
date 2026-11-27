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
    /** The weight of each kind of file. */
    public const KIND_WEIGHTS = [
        "docs"   => 0.3,
        "test"   => 0.5,
        "config" => 0.6,
        "source" => 1.0,
        "infra"  => 1.3,
        "sql"    => 1.5,
    ];

    /**
     * The effort buckets, from the lightest, with the minimum score of each.
     *
     * @var array<string, float>
     */
    public const BUCKETS = [
        "⚡ Trivial (~5 min)"           => 0.0,
        "☕ Light (~15 min)"            => 5.0,
        "🧠 Moderate (~30 min)"         => 15.0,
        "🔍 Heavy (~1 h)"               => 35.0,
        "🪓 Very heavy (consider splitting)" => 70.0,
    ];

    /** The points per hunk of a file. */
    private const HUNK_POINTS = 0.5;

    /** The points per branching token added in a code file. */
    private const COMPLEXITY_POINTS = 0.3;

    /** The points per extra module touched. */
    private const MODULE_POINTS = 1.5;

    /** The points per extra language touched. */
    private const LANGUAGE_POINTS = 1.0;

    /** The points for a binary file, which has no lines to count. */
    private const BINARY_POINTS = 1.0;

    /** The multiplier when a sensitive path changes. */
    private const RISK_MULTIPLIER = 1.3;

    /** The weight of the deleted lines of a file, against its added lines. */
    private const DELETION_WEIGHT = 0.3;

    /** The weight of the lines of a deleted file. */
    private const DELETED_FILE_WEIGHT = 0.1;

    /** The weight of the lines of a new file (read once, without a before to compare). */
    private const NEW_FILE_WEIGHT = 0.8;

    /** The changed source lines from which a pull request without test changes is flagged. */
    private const MISSING_TESTS_MIN_LINES = 10;

    /** The kinds whose added lines are scanned for branching. */
    private const CODE_KINDS = ["source", "test", "infra", "sql"];

    /** Sensitive paths: a change to any of them makes the review heavier. */
    private const RISK_PATTERNS = [
        '/(^|\/)(auth|authentication|authorization|security|crypto|oauth|sso|permissions?)(\/|\.|_|$)/i',
        '/(token|secret|password|credential|encrypt|decrypt)[^\/]*$/i',
        '/(^|\/)migrations?\//i',
        '/\.sql$/i',
        '/^\.github\/workflows\//i',
        '/(^|\/)Dockerfile[^\/]*$/i',
        '/(^|\/)docker-compose[^\/]*\.ya?ml$/i',
        '/(^|\/)\.env[^\/]*$/i',
        '/(^|\/)\.htaccess$/i',
    ];

    /** Branching tokens counted in the added lines of code files. */
    private const COMPLEXITY_PATTERN = '/\b(if|elseif|elif|for|foreach|while|case|catch|except|when)\b|&&|\|\|/';

    /** Lines that are comments only, whose words aren't branching. */
    private const COMMENT_PATTERN = '/^\s*(\/\/|#|\*|\/\*|--|<!--)/';

    /**
     * Detects the generated files and reads the paths of the diff.
     *
     * @var PullRequestSizeLabeler
     */
    private PullRequestSizeLabeler $sizeLabeler;


    /**
     * @param PullRequestSizeLabeler|null $sizeLabeler Detects the generated files (shared with the size label).
     */
    public function __construct(?PullRequestSizeLabeler $sizeLabeler=null)
    {
        $this->sizeLabeler = ($sizeLabeler ?? new PullRequestSizeLabeler());

    }//end __construct()


    /**
     * Estimates the review effort of a unified diff.
     *
     * @param string $diff The pull request diff (application/vnd.github.v3.diff).
     *
     * @return array{score: float, bucket: string, files: array<int, array<string, mixed>>, modules: string[], languages: string[], riskyFiles: string[], ignoredFiles: string[], hunks: int, complexity: int, missingTests: bool}
     */
    public function estimateDiff(string $diff): array
    {
        $files        = [];
        $ignoredFiles = [];

        foreach ($this->splitFiles($diff) as $lines) {
            $file = $this->parseFile($lines);
            if ($file === null) {
                continue;
            }

            if ($this->sizeLabeler->isGenerated($file["path"]) === true) {
                $ignoredFiles[] = $file["path"];
                continue;
            }

            $files[] = $file;
        }

        return $this->summarise($files, array_values(array_unique($ignoredFiles)));

    }//end estimateDiff()


    /**
     * A rough estimate from the pull request's own totals, for when the diff can't be read.
     *
     * @param int $changedFiles The number of changed files.
     * @param int $additions    The added lines.
     * @param int $deletions    The deleted lines.
     *
     * @return array{score: float, bucket: string}
     */
    public function estimateTotals(int $changedFiles, int $additions, int $deletions): array
    {
        $score = sqrt($additions + (self::DELETION_WEIGHT * $deletions)) + (self::HUNK_POINTS * $changedFiles);
        $score = round($score, 1);

        return ["score" => $score, "bucket" => $this->bucketFor($score)];

    }//end estimateTotals()


    /**
     * The check run output for an estimate from the diff.
     *
     * @param array $estimate The result of estimateDiff().
     *
     * @return string Markdown.
     */
    public function buildReport(array $estimate): string
    {
        $maxFiles  = 10;
        $maxListed = 10;

        $report  = "Review effort: **{$estimate["bucket"]}** — score {$estimate["score"]}.\n\n";
        $report .= "| Signal | Value |\n|---|---|\n";
        $report .= "| Files counted | " . count($estimate["files"]) . " |\n";
        $report .= "| Hunks | {$estimate["hunks"]} |\n";
        $report .= "| Branching added | {$estimate["complexity"]} |\n";
        $report .= "| Modules | " . count($estimate["modules"]) . " |\n";
        $report .= "| Languages | " . (count($estimate["languages"]) === 0 ? "0" : count($estimate["languages"]) . " (" . implode(", ", $estimate["languages"]) . ")") . " |\n";
        $report .= "| Sensitive files | " . count($estimate["riskyFiles"]) . " |\n";

        if (count($estimate["files"]) > 0) {
            $report .= "\n**Heaviest files**\n\n| File | Kind | Status | +/- | Hunks | Score |\n|---|---|---|---|---|---|\n";
            foreach (array_slice($estimate["files"], 0, $maxFiles) as $file) {
                $path    = str_replace("|", "\\|", mb_substr($file["path"], 0, 120));
                $report .= "| `{$path}` | {$file["kind"]} | {$file["status"]} | +{$file["additions"]}/-{$file["deletions"]} | {$file["hunks"]} | {$file["score"]} |\n";
            }
        }

        $notes = [];
        if (count($estimate["riskyFiles"]) > 0) {
            $listed  = array_map(static fn ($f) => "`" . mb_substr($f, 0, 120) . "`", array_slice($estimate["riskyFiles"], 0, $maxListed));
            $more    = count($estimate["riskyFiles"]) > $maxListed ? " and " . (count($estimate["riskyFiles"]) - $maxListed) . " more" : "";
            $notes[] = "🔐 Sensitive paths changed (score × " . self::RISK_MULTIPLIER . "): " . implode(", ", $listed) . "{$more}.";
        }

        if ($estimate["missingTests"] === true) {
            $notes[] = "🧪 Source code changed without any test changes.";
        }

        if (count($estimate["ignoredFiles"]) > 0) {
            $notes[] = count($estimate["ignoredFiles"]) . " generated file(s) not counted.";
        }

        if (count($notes) > 0) {
            $report .= "\n" . implode("\n\n", $notes) . "\n";
        }

        $report .= "\n<sub>The estimate weighs each file by its kind, the square root of its lines, its hunks and the branching it adds, plus the modules and languages the change spans. It is a heuristic, not a measure.</sub>";

        return $report;

    }//end buildReport()


    /**
     * The bucket of a score.
     *
     * @param float $score The effort score.
     *
     * @return string One of the BUCKETS.
     */
    public function bucketFor(float $score): string
    {
        $bucket = array_key_first(self::BUCKETS);
        foreach (self::BUCKETS as $name => $minimum) {
            if ($score >= $minimum) {
                $bucket = $name;
            }
        }

        return $bucket;

    }//end bucketFor()


    /**
     * The kind of a file, which sets its weight.
     *
     * @param string $path The file path in the repository.
     *
     * @return string One of the keys of KIND_WEIGHTS.
     */
    public function kindOf(string $path): string
    {
        $name      = strtolower(basename($path));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (preg_match('/(^|\/)(tests?|__tests__|spec|specs)\//i', $path) === 1
            || preg_match('/(Test|Tests|Spec)\.[a-z]+$|\.(test|spec)\.[a-z]+$|^test_.*\.py$|_test\.(go|py)$/i', basename($path)) === 1
        ) {
            return "test";
        }

        if ($extension === "sql" || preg_match('/(^|\/)migrations?\//i', $path) === 1) {
            return "sql";
        }

        if (preg_match('/^\.github\/workflows\//i', $path) === 1
            || str_starts_with($name, "dockerfile")
            || preg_match('/^docker-compose.*\.ya?ml$/', $name) === 1
            || in_array($extension, ["tf", "tfvars", "hcl", "sh", "bash", "ps1"], true)
            || $name === ".htaccess"
            || $name === "makefile"
        ) {
            return "infra";
        }

        if (in_array($extension, ["md", "markdown", "rst", "txt", "adoc"], true)
            || preg_match('/(^|\/)docs?\//i', $path) === 1
            || in_array($name, ["license", "changelog", "authors", "contributors"], true)
        ) {
            return "docs";
        }

        if (in_array($extension, ["json", "yml", "yaml", "xml", "ini", "toml", "cfg", "conf", "config", "properties", "editorconfig", "gitignore", "gitattributes", "csv", "lock", "svg"], true)
            || str_starts_with($name, ".")
        ) {
            return "config";
        }

        return "source";

    }//end kindOf()


    /**
     * Whether a path is sensitive.
     *
     * @param string $path The file path in the repository.
     *
     * @return boolean
     */
    public function isRisky(string $path): bool
    {
        foreach (self::RISK_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;

    }//end isRisky()


    /**
     * Splits a diff into the lines of each file, from its "diff --git" line.
     *
     * @param string $diff The unified diff.
     *
     * @return array<int, string[]>
     */
    private function splitFiles(string $diff): array
    {
        $files   = [];
        $current = null;

        foreach (explode("\n", str_replace("\r\n", "\n", $diff)) as $line) {
            if (str_starts_with($line, "diff --git ")) {
                if ($current !== null) {
                    $files[] = $current;
                }

                $current = [$line];
                continue;
            }

            if ($current !== null) {
                $current[] = $line;
            }
        }

        if ($current !== null) {
            $files[] = $current;
        }

        return $files;

    }//end splitFiles()


    /**
     * Reads one file of the diff: its path, status, lines, hunks and branching added.
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

        $file = [
            "path"       => $path,
            "kind"       => $this->kindOf($path),
            "status"     => "modified",
            "additions"  => 0,
            "deletions"  => 0,
            "hunks"      => 0,
            "complexity" => 0,
            "risky"      => $this->isRisky($path),
            "score"      => 0.0,
        ];

        $inHeader = true;
        $isCode   = in_array($file["kind"], self::CODE_KINDS, true);

        foreach (array_slice($lines, 1) as $line) {
            if (str_starts_with($line, "@@")) {
                $inHeader = false;
                $file["hunks"]++;
                continue;
            }

            if ($inHeader === true) {
                $file["status"] = $this->statusFromHeader($line, $file["status"]);
                continue;
            }

            if (str_starts_with($line, "+")) {
                $file["additions"]++;
                $added = substr($line, 1);
                if ($isCode === true && preg_match(self::COMMENT_PATTERN, $added) !== 1) {
                    $file["complexity"] += preg_match_all(self::COMPLEXITY_PATTERN, $added);
                }

                continue;
            }

            if (str_starts_with($line, "-")) {
                $file["deletions"]++;
            }
        }//end foreach

        $file["score"] = $this->scoreFile($file);

        return $file;

    }//end parseFile()


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
        if (str_starts_with($line, "new file mode")) {
            return "added";
        }

        if (str_starts_with($line, "deleted file mode")) {
            return "deleted";
        }

        if (str_starts_with($line, "rename from") && $current === "modified") {
            return "renamed";
        }

        if (str_starts_with($line, "Binary files") || str_starts_with($line, "GIT binary patch")) {
            return "binary";
        }

        return $current;

    }//end statusFromHeader()


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

        $weight = self::KIND_WEIGHTS[$file["kind"]];
        $lines  = $file["additions"] + (self::DELETION_WEIGHT * $file["deletions"]);

        if ($file["status"] === "deleted") {
            $lines = (self::DELETED_FILE_WEIGHT * $file["deletions"]);
        }

        if ($file["status"] === "added") {
            $lines *= self::NEW_FILE_WEIGHT;
        }

        $hunks = $file["status"] === "deleted" ? 0 : $file["hunks"];

        $score  = $weight * sqrt($lines);
        $score += self::HUNK_POINTS * $hunks;
        $score += self::COMPLEXITY_POINTS * $weight * $file["complexity"];

        return round($score, 2);

    }//end scoreFile()


    /**
     * Adds up the files, the spread across modules and languages, and the risk.
     *
     * @param array<int, array<string, mixed>> $files        The counted files.
     * @param string[]                         $ignoredFiles The generated files left out.
     *
     * @return array{score: float, bucket: string, files: array<int, array<string, mixed>>, modules: string[], languages: string[], riskyFiles: string[], ignoredFiles: string[], hunks: int, complexity: int, missingTests: bool}
     */
    private function summarise(array $files, array $ignoredFiles): array
    {
        $score        = 0.0;
        $modules      = [];
        $languages    = [];
        $riskyFiles   = [];
        $hunks        = 0;
        $complexity   = 0;
        $sourceLines  = 0;
        $testsChanged = false;

        foreach ($files as $file) {
            $score      += $file["score"];
            $hunks      += $file["hunks"];
            $complexity += $file["complexity"];
            $modules[$this->moduleOf($file["path"])] = true;

            $extension = strtolower(pathinfo($file["path"], PATHINFO_EXTENSION));
            if (in_array($file["kind"], self::CODE_KINDS, true) && $extension !== "") {
                $languages[$extension] = true;
            }

            if ($file["risky"] === true) {
                $riskyFiles[] = $file["path"];
            }

            if ($file["kind"] === "test") {
                $testsChanged = true;
            }

            if ($file["kind"] === "source" && $file["status"] !== "deleted") {
                $sourceLines += $file["additions"] + $file["deletions"];
            }
        }//end foreach

        $score += self::MODULE_POINTS * max(0, count($modules) - 1);
        $score += self::LANGUAGE_POINTS * max(0, count($languages) - 1);
        if (count($riskyFiles) > 0) {
            $score *= self::RISK_MULTIPLIER;
        }

        $score = round($score, 1);
        usort($files, static fn (array $a, array $b) => $b["score"] <=> $a["score"]);

        return [
            "score"        => $score,
            "bucket"       => $this->bucketFor($score),
            "files"        => $files,
            "modules"      => array_keys($modules),
            "languages"    => array_keys($languages),
            "riskyFiles"   => $riskyFiles,
            "ignoredFiles" => $ignoredFiles,
            "hunks"        => $hunks,
            "complexity"   => $complexity,
            "missingTests" => ($testsChanged === false && $sourceLines >= self::MISSING_TESTS_MIN_LINES),
        ];

    }//end summarise()


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
