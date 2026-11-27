<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Builds the output of the review effort check run from an estimate.
 */
class ReviewEffortReportBuilder
{
    /** The heaviest files listed. */
    public const MAX_FILES = 10;

    /** The sensitive files listed. */
    public const MAX_LISTED = 10;

    /** The longest path shown, in characters. */
    public const MAX_PATH_LENGTH = 120;


    /**
     * The check run output for an estimate from the diff.
     *
     * @param array<string, mixed> $estimate The result of ReviewEffortEstimator::estimateDiff().
     *
     * @return string Markdown.
     */
    public function build(array $estimate): string
    {
        $report  = "Review effort: **{$estimate["bucket"]}** — score {$estimate["score"]}.\n\n";
        $report .= $this->signalsTable($estimate);
        $report .= $this->filesTable($estimate["files"]);
        $report .= $this->notes($estimate);
        $report .= "\n<sub>The estimate weighs each file by its kind, the square root of its lines, its hunks and the branching it adds, plus the modules and languages the change spans. It is a heuristic, not a measure.</sub>";

        return $report;

    }//end build()


    /**
     * The check run output for a rough estimate from the pull request totals.
     *
     * @param array<string, mixed> $estimate   The result of ReviewEffortEstimator::estimateTotals().
     * @param integer              $statusCode The HTTP status GitHub answered the diff request with.
     *
     * @return string Markdown.
     */
    public function buildRough(array $estimate, int $statusCode): string
    {
        $report  = "Review effort: **{$estimate["bucket"]}** — score {$estimate["score"]} (rough).\n\n";
        $report .= "The diff could not be read (HTTP {$statusCode}), so the estimate only uses the number of changed files and lines.";

        return $report;

    }//end buildRough()


    /**
     * The table of the signals behind the score.
     *
     * @param array<string, mixed> $estimate The estimate.
     *
     * @return string
     */
    private function signalsTable(array $estimate): string
    {
        $files     = count($estimate["files"]);
        $modules   = count($estimate["modules"]);
        $sensitive = count($estimate["riskyFiles"]);
        $languages = $this->languagesCell($estimate["languages"]);

        $table  = "| Signal | Value |\n|---|---|\n";
        $table .= "| Files counted | {$files} |\n";
        $table .= "| Hunks | {$estimate["hunks"]} |\n";
        $table .= "| Branching added | {$estimate["complexity"]} |\n";
        $table .= "| Modules | {$modules} |\n";
        $table .= "| Languages | {$languages} |\n";
        $table .= "| Sensitive files | {$sensitive} |\n";

        return $table;

    }//end signalsTable()


    /**
     * The number of languages, with their extensions.
     *
     * @param string[] $languages The extensions of the code files.
     *
     * @return string
     */
    private function languagesCell(array $languages): string
    {
        if (count($languages) === 0) {
            return "0";
        }

        return sprintf("%d (%s)", count($languages), implode(", ", $languages));

    }//end languagesCell()


    /**
     * The table of the heaviest files.
     *
     * @param array<int, array<string, mixed>> $files The files, heaviest first.
     *
     * @return string Empty when no file was counted.
     */
    private function filesTable(array $files): string
    {
        if (count($files) === 0) {
            return "";
        }

        $table = "\n**Heaviest files**\n\n| File | Kind | Status | +/- | Hunks | Score |\n|---|---|---|---|---|---|\n";
        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            $path   = str_replace("|", "\\|", $this->codeSpan($file["path"]));
            $table .= "| {$path} | {$file["kind"]} | {$file["status"]} | +{$file["additions"]}/-{$file["deletions"]} | {$file["hunks"]} | {$file["score"]} |\n";
        }

        return $table;

    }//end filesTable()


    /**
     * The notes on sensitive paths, missing tests and generated files.
     *
     * @param array<string, mixed> $estimate The estimate.
     *
     * @return string Empty when there is nothing to note.
     */
    private function notes(array $estimate): string
    {
        $notes = [];
        if (count($estimate["riskyFiles"]) > 0) {
            $multiplier = ReviewEffortEstimator::RISK_MULTIPLIER;
            $notes[]    = "🔐 Sensitive paths changed (score × {$multiplier}): {$this->listPaths($estimate["riskyFiles"])}.";
        }

        if ($estimate["missingTests"] === true) {
            $notes[] = "🧪 Source code changed without any test changes.";
        }

        $ignored = count($estimate["ignoredFiles"]);
        if ($ignored > 0) {
            $notes[] = "{$ignored} generated file(s) not counted.";
        }

        if (count($notes) === 0) {
            return "";
        }

        return sprintf("\n%s\n", implode("\n\n", $notes));

    }//end notes()


    /**
     * The first paths of a list, as code, and how many more there are.
     *
     * @param string[] $paths The paths.
     *
     * @return string
     */
    private function listPaths(array $paths): string
    {
        $listed = [];
        foreach (array_slice($paths, 0, self::MAX_LISTED) as $path) {
            $listed[] = $this->codeSpan($path);
        }

        $more = (count($paths) - self::MAX_LISTED);
        if ($more > 0) {
            return sprintf("%s and %d more", implode(", ", $listed), $more);
        }

        return implode(", ", $listed);

    }//end listPaths()


    /**
     * A path as inline code, shortened and on one line. A path holding
     * backticks (Git quotes and decodes such paths) is fenced with one more
     * backtick than its longest run, and padded with spaces.
     *
     * @param string $path The file path.
     *
     * @return string
     */
    private function codeSpan(string $path): string
    {
        $short = str_replace(["\r", "\n"], " ", mb_substr($path, 0, self::MAX_PATH_LENGTH));
        preg_match_all('/`+/', $short, $runs);
        if (count($runs[0]) === 0) {
            return "`{$short}`";
        }

        $fence = str_repeat("`", (max(array_map("strlen", $runs[0])) + 1));

        return "{$fence} {$short} {$fence}";

    }//end codeSpan()


}//end class
