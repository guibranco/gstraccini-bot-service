<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\ReviewEffortEstimator;
use GuiBranco\GStracciniBot\Library\ReviewEffortReportBuilder;
use PHPUnit\Framework\TestCase;

/**
 * How ReviewEffortReportBuilder writes the output of the check run.
 */
class ReviewEffortReportBuilderTest extends TestCase
{
    /** A sensitive source file. */
    private const RISKY_FILE = "Src/Library/TokenStore.php";

    /**
     * The builder under test.
     *
     * @var ReviewEffortReportBuilder
     */
    private ReviewEffortReportBuilder $builder;

    /**
     * Makes the estimates to report.
     *
     * @var ReviewEffortEstimator
     */
    private ReviewEffortEstimator $estimator;

    /**
     * Builds the diffs.
     *
     * @var ReviewEffortDiffFixtures
     */
    private ReviewEffortDiffFixtures $fixtures;


    /**
     * Starts each test with a builder, an estimator and the fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->builder   = new ReviewEffortReportBuilder();
        $this->estimator = new ReviewEffortEstimator();
        $this->fixtures  = new ReviewEffortDiffFixtures();

    }//end setUp()


    /**
     * The report of a diff.
     *
     * @param string $diff The unified diff.
     *
     * @return string
     */
    private function reportOf(string $diff): string
    {
        return $this->builder->build($this->estimator->estimateDiff($diff));

    }//end reportOf()


    /**
     * The report gives the bucket, the signals and the heaviest files.
     *
     * @return void
     */
    public function testReportListsTheSignals(): void
    {
        $report = $this->reportOf($this->fixtures->addedDiff(self::RISKY_FILE, 20, ReviewEffortDiffFixtures::BRANCHING_CODE));

        $this->assertStringStartsWith("Review effort: **", $report);
        $this->assertStringContainsString("| Files counted | 1 |", $report);
        $this->assertStringContainsString("| Hunks | 1 |", $report);
        $this->assertStringContainsString("| Branching added | 40 |", $report);
        $this->assertStringContainsString("| Languages | 1 (php) |", $report);
        $this->assertStringContainsString("| `Src/Library/TokenStore.php` | source | modified | +20/-0 | 1 |", $report);

    }//end testReportListsTheSignals()


    /**
     * The report notes sensitive paths, missing tests and generated files.
     *
     * @return void
     */
    public function testReportAddsTheNotes(): void
    {
        $diff  = $this->fixtures->addedDiff(self::RISKY_FILE, 20, ReviewEffortDiffFixtures::PLAIN_CODE);
        $diff .= $this->fixtures->addedDiff("composer.lock", 10, '"x"');

        $report = $this->reportOf($diff);

        $this->assertStringContainsString("🔐 Sensitive paths changed (score × 1.3): `Src/Library/TokenStore.php`.", $report);
        $this->assertStringContainsString("🧪 Source code changed without any test changes.", $report);
        $this->assertStringContainsString("1 generated file(s) not counted.", $report);

    }//end testReportAddsTheNotes()


    /**
     * A pipe in a path doesn't break the table.
     *
     * @return void
     */
    public function testReportEscapesPipesInPaths(): void
    {
        $report = $this->reportOf($this->fixtures->addedDiff("docs/a|b.md", 1, "x"));

        $this->assertStringContainsString("`docs/a\\|b.md`", $report);

    }//end testReportEscapesPipesInPaths()


    /**
     * A path with a line break or a backtick stays on one row and in one code span.
     *
     * @return void
     */
    public function testReportKeepsOddPathsOnOneRow(): void
    {
        $diff  = "diff --git \"a/docs/line\\nbreak.md\" \"b/docs/line\\nbreak.md\"\n@@ -1,1 +1,1 @@\n+x\n";
        $diff .= $this->fixtures->addedDiff("docs/back`tick.md", 1, "x");

        $report = $this->reportOf($diff);

        $this->assertStringContainsString("| `docs/line break.md` | docs |", $report);
        $this->assertStringContainsString("| `` docs/back`tick.md `` | docs |", $report);

    }//end testReportKeepsOddPathsOnOneRow()


    /**
     * A run of backticks in a path gets a longer fence, in the table and in the notes.
     *
     * @return void
     */
    public function testFencesBacktickRunsInPaths(): void
    {
        $report = $this->reportOf($this->fixtures->addedDiff("Src/Auth/back``tick.php", 1, ReviewEffortDiffFixtures::PLAIN_CODE));

        $this->assertStringContainsString("| ``` Src/Auth/back``tick.php ``` | source |", $report);
        $this->assertStringContainsString("(score × 1.3): ``` Src/Auth/back``tick.php ```.", $report);

    }//end testFencesBacktickRunsInPaths()


    /**
     * With no file counted, there is no files table and no note.
     *
     * @return void
     */
    public function testReportWithoutFilesOrNotes(): void
    {
        $report = $this->reportOf("");

        $this->assertStringContainsString("| Languages | 0 |", $report);
        $this->assertStringNotContainsString("Heaviest files", $report);
        $this->assertStringNotContainsString("🔐", $report);

    }//end testReportWithoutFilesOrNotes()


    /**
     * At most ten sensitive paths are listed, with how many more there are.
     *
     * @return void
     */
    public function testListsAtMostTenSensitivePaths(): void
    {
        $diff = "";
        for ($index = 1; $index <= 12; $index++) {
            $diff .= $this->fixtures->addedDiff("Src/Auth/Handler{$index}.php", 1, ReviewEffortDiffFixtures::PLAIN_CODE);
        }

        $report = $this->reportOf($diff);

        $this->assertStringContainsString("`Src/Auth/Handler10.php` and 2 more.", $report);
        $this->assertStringNotContainsString("`Src/Auth/Handler11.php`", $report);

    }//end testListsAtMostTenSensitivePaths()


    /**
     * The rough report says the diff could not be read.
     *
     * @return void
     */
    public function testRoughReport(): void
    {
        $report = $this->builder->buildRough($this->estimator->estimateTotals(10, 100, 0), 406);

        $this->assertStringContainsString("**🧠 Moderate (~30 min)** — score 15 (rough).", $report);
        $this->assertStringContainsString("(HTTP 406)", $report);

    }//end testRoughReport()


}//end class
