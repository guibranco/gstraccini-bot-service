<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\ReviewEffortEstimator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReviewEffortEstimatorTest extends TestCase
{
    private ReviewEffortEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new ReviewEffortEstimator();
    }

    /**
     * Builds the diff of one file, one hunk per entry of $hunks (each a list of "+..."/"-..." lines).
     *
     * @param string     $path   The file path.
     * @param string[][] $hunks  The changed lines of each hunk.
     * @param string[]   $header Extra header lines ("new file mode 100644", ...).
     */
    private function fileDiff(string $path, array $hunks, array $header = []): string
    {
        $lines = array_merge(["diff --git a/{$path} b/{$path}"], $header, ["index 111..222 100644", "--- a/{$path}", "+++ b/{$path}"]);
        foreach ($hunks as $i => $hunk) {
            $lines[] = "@@ -" . ($i * 10 + 1) . ",3 +" . ($i * 10 + 1) . ",3 @@";
            $lines[] = " context";
            foreach ($hunk as $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return string[] $count added lines of plain code. */
    private function added(int $count, string $text = '$a = 1;'): array
    {
        return array_fill(0, $count, "+{$text}");
    }

    public function testLockFilesDoNotCount(): void
    {
        $diff = $this->fileDiff("package-lock.json", [$this->added(2000, '"x": "1.0.0",')]);

        $estimate = $this->estimator->estimateDiff($diff);

        $this->assertSame(0.0, $estimate["score"]);
        $this->assertSame(["package-lock.json"], $estimate["ignoredFiles"]);
        $this->assertSame("⚡ Trivial (~5 min)", $estimate["bucket"]);
    }

    public function testScatteredSmallChangeOutweighsOneLongBlock(): void
    {
        // 300 plain lines in one hunk of one file.
        $block = $this->estimator->estimateDiff($this->fileDiff("Src/Library/Long.php", [$this->added(300)]));

        // 40 branching lines spread over 8 hunks in 4 files of 4 modules.
        $scattered = "";
        foreach (["Src/Handlers/A.php", "Src/Library/B.php", "Src/lib/c.php", "Src/config/d.php"] as $path) {
            $scattered .= $this->fileDiff($path, [$this->added(5, 'if ($a && $b) {'), $this->added(5, 'foreach ($x as $y) {')]);
        }
        $scattered = $this->estimator->estimateDiff($scattered);

        $this->assertGreaterThan($block["score"], $scattered["score"]);
        $this->assertSame(8, $scattered["hunks"]);
        $this->assertSame(60, $scattered["complexity"]);
        $this->assertCount(4, $scattered["modules"]);
    }

    public function testBranchingInCommentsDoesNotCount(): void
    {
        $estimate = $this->estimator->estimateDiff($this->fileDiff("Src/a.php", [["+// if this and that", "+ * while waiting", "+if (\$a) {"]]));

        $this->assertSame(1, $estimate["complexity"]);
    }

    public function testBranchingInDocsDoesNotCount(): void
    {
        $estimate = $this->estimator->estimateDiff($this->fileDiff("README.md", [["+Use it if you want, for fun, while it lasts."]]));

        $this->assertSame(0, $estimate["complexity"]);
        $this->assertSame("docs", $estimate["files"][0]["kind"]);
    }

    public function testDocsWeighLessThanSource(): void
    {
        $docs = $this->estimator->estimateDiff($this->fileDiff("docs/guide.md", [$this->added(100, "Some text.")]));
        $source = $this->estimator->estimateDiff($this->fileDiff("Src/guide.php", [$this->added(100)]));

        $this->assertLessThan($source["score"], $docs["score"]);
    }

    public function testSensitivePathsRaiseTheScore(): void
    {
        $plain = $this->estimator->estimateDiff($this->fileDiff("Src/Library/Labels.php", [$this->added(50)]));
        $risky = $this->estimator->estimateDiff($this->fileDiff("Src/Library/TokenStore.php", [$this->added(50)]));

        $this->assertSame([], $plain["riskyFiles"]);
        $this->assertSame(["Src/Library/TokenStore.php"], $risky["riskyFiles"]);
        $this->assertEqualsWithDelta($plain["score"] * 1.3, $risky["score"], 0.11);
    }

    public function testDeletedFilesWeighLittle(): void
    {
        $removed = array_fill(0, 400, '-$a = 1;');
        $estimate = $this->estimator->estimateDiff($this->fileDiff("Src/Old.php", [$removed], ["deleted file mode 100644"]));

        $this->assertSame("deleted", $estimate["files"][0]["status"]);
        $this->assertLessThan(7.0, $estimate["score"]);
    }

    public function testPureRenameWeighsNothing(): void
    {
        $diff = "diff --git a/Src/Old.php b/Src/New.php\nsimilarity index 100%\nrename from Src/Old.php\nrename to Src/New.php\n";

        $estimate = $this->estimator->estimateDiff($diff);

        $this->assertSame("renamed", $estimate["files"][0]["status"]);
        $this->assertSame("Src/New.php", $estimate["files"][0]["path"]);
        $this->assertSame(0.0, $estimate["score"]);
    }

    public function testBinaryFilesHaveAFixedScore(): void
    {
        $diff = "diff --git a/img/logo.png b/img/logo.png\nindex 111..222 100644\nBinary files a/img/logo.png and b/img/logo.png differ\n";

        $estimate = $this->estimator->estimateDiff($diff);

        $this->assertSame("binary", $estimate["files"][0]["status"]);
        $this->assertSame(1.0, $estimate["score"]);
    }

    public function testFlagsSourceChangesWithoutTests(): void
    {
        $withoutTests = $this->estimator->estimateDiff($this->fileDiff("Src/Library/A.php", [$this->added(20)]));
        $withTests = $this->estimator->estimateDiff(
            $this->fileDiff("Src/Library/A.php", [$this->added(20)]) . $this->fileDiff("Src/Tests/Library/ATest.php", [$this->added(20)])
        );

        $this->assertTrue($withoutTests["missingTests"]);
        $this->assertFalse($withTests["missingTests"]);
    }

    public function testCountsLanguagesOfCodeFilesOnly(): void
    {
        $diff = $this->fileDiff("Src/a.php", [$this->added(1)])
            . $this->fileDiff("Sql/0018.sql", [$this->added(1, "SELECT 1;")])
            . $this->fileDiff("README.md", [$this->added(1, "text")])
            . $this->fileDiff("config.json", [$this->added(1, "{}")]);

        $estimate = $this->estimator->estimateDiff($diff);

        $this->assertSame(["php", "sql"], $estimate["languages"]);
    }

    public function testFilesAreSortedFromTheHeaviest(): void
    {
        $diff = $this->fileDiff("README.md", [$this->added(1, "text")]) . $this->fileDiff("Src/a.php", [$this->added(50)]);

        $estimate = $this->estimator->estimateDiff($diff);

        $this->assertSame("Src/a.php", $estimate["files"][0]["path"]);
    }

    public function testHandlesWindowsLineEndings(): void
    {
        $diff = str_replace("\n", "\r\n", $this->fileDiff("Src/a.php", [$this->added(4, 'if ($a) {')]));

        $estimate = $this->estimator->estimateDiff($diff);

        $this->assertSame(4, $estimate["files"][0]["additions"]);
        $this->assertSame(4, $estimate["complexity"]);
    }

    #[DataProvider('kindProvider')]
    public function testKindOf(string $path, string $kind): void
    {
        $this->assertSame($kind, $this->estimator->kindOf($path));
    }

    public static function kindProvider(): array
    {
        return [
            ["Src/Library/Foo.php", "source"],
            ["Src/Tests/Library/FooTest.php", "test"],
            ["web/src/app.test.ts", "test"],
            ["pkg/foo_test.go", "test"],
            ["Sql/0018.add_table.sql", "sql"],
            ["db/migrations/2026_add.php", "sql"],
            [".github/workflows/build.yml", "infra"],
            ["Dockerfile", "infra"],
            ["docker-compose.yml", "infra"],
            ["deploy/run.sh", "infra"],
            ["README.md", "docs"],
            ["docs/adr/0001.md", "docs"],
            ["Src/config/labels.json", "config"],
            [".editorconfig", "config"],
        ];
    }

    #[DataProvider('bucketProvider')]
    public function testBucketFor(float $score, string $bucket): void
    {
        $this->assertSame($bucket, $this->estimator->bucketFor($score));
    }

    public static function bucketProvider(): array
    {
        return [
            [0.0, "⚡ Trivial (~5 min)"],
            [4.9, "⚡ Trivial (~5 min)"],
            [5.0, "☕ Light (~15 min)"],
            [15.0, "🧠 Moderate (~30 min)"],
            [35.0, "🔍 Heavy (~1 h)"],
            [70.0, "🪓 Very heavy (consider splitting)"],
            [500.0, "🪓 Very heavy (consider splitting)"],
        ];
    }

    public function testEstimateTotals(): void
    {
        $estimate = $this->estimator->estimateTotals(10, 100, 0);

        $this->assertSame(15.0, $estimate["score"]);
        $this->assertSame("🧠 Moderate (~30 min)", $estimate["bucket"]);
    }

    public function testReportListsTheSignalsAndNotes(): void
    {
        $diff = $this->fileDiff("Src/Library/TokenStore.php", [$this->added(20, 'if ($a) {')])
            . $this->fileDiff("composer.lock", [$this->added(10, '"x"')]);

        $report = $this->estimator->buildReport($this->estimator->estimateDiff($diff));

        $this->assertStringContainsString("Review effort: **", $report);
        $this->assertStringContainsString("| Hunks | 1 |", $report);
        $this->assertStringContainsString("| Branching added | 20 |", $report);
        $this->assertStringContainsString("`Src/Library/TokenStore.php` | source | modified | +20/-0 | 1 |", $report);
        $this->assertStringContainsString("Sensitive paths changed", $report);
        $this->assertStringContainsString("Source code changed without any test changes.", $report);
        $this->assertStringContainsString("1 generated file(s) not counted.", $report);
    }

    public function testReportEscapesPipesInPaths(): void
    {
        $report = $this->estimator->buildReport($this->estimator->estimateDiff($this->fileDiff("docs/a|b.md", [$this->added(1, "x")])));

        $this->assertStringContainsString("`docs/a\\|b.md`", $report);
    }
}
