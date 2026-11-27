<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\ReviewEffortEstimator;
use PHPUnit\Framework\TestCase;

/**
 * How ReviewEffortEstimator sums up a pull request: tests, languages, modules and buckets.
 */
class ReviewEffortSummaryTest extends TestCase
{
    /** A source file of the repository. */
    private const SOURCE_FILE = "Src/Library/LabelService.php";

    /**
     * The estimator under test.
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
     * Starts each test with an estimator and the fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->estimator = new ReviewEffortEstimator();
        $this->fixtures  = new ReviewEffortDiffFixtures();

    }//end setUp()


    /**
     * Source changes with no test changes are flagged.
     *
     * @return void
     */
    public function testFlagsSourceWithoutTests(): void
    {
        $estimate = $this->estimator->estimateDiff($this->fixtures->addedDiff(self::SOURCE_FILE, 20, ReviewEffortDiffFixtures::PLAIN_CODE));

        $this->assertTrue($estimate["missingTests"]);

    }//end testFlagsSourceWithoutTests()


    /**
     * Source changes with test changes, or too small to matter, are not flagged.
     *
     * @return void
     */
    public function testDoesNotFlagTestedOrTinyChanges(): void
    {
        $tested  = $this->fixtures->addedDiff(self::SOURCE_FILE, 20, ReviewEffortDiffFixtures::PLAIN_CODE);
        $tested .= $this->fixtures->addedDiff("Src/Tests/Library/LabelServiceTest.php", 20, ReviewEffortDiffFixtures::PLAIN_CODE);
        $tiny    = $this->fixtures->addedDiff(self::SOURCE_FILE, 3, ReviewEffortDiffFixtures::PLAIN_CODE);

        $this->assertFalse($this->estimator->estimateDiff($tested)["missingTests"]);
        $this->assertFalse($this->estimator->estimateDiff($tiny)["missingTests"]);

    }//end testDoesNotFlagTestedOrTinyChanges()


    /**
     * Only the code files count toward the languages.
     *
     * @return void
     */
    public function testCountsLanguagesOfCodeOnly(): void
    {
        $diff  = $this->fixtures->addedDiff("Src/a.php", 1, ReviewEffortDiffFixtures::PLAIN_CODE);
        $diff .= $this->fixtures->addedDiff("Sql/0018.sql", 1, "SELECT 1;");
        $diff .= $this->fixtures->addedDiff("README.md", 1, "text");
        $diff .= $this->fixtures->addedDiff("config.json", 1, "{}");

        $estimate = $this->estimator->estimateDiff($diff);

        $this->assertSame(["php", "sql"], $estimate["languages"]);

    }//end testCountsLanguagesOfCodeOnly()


    /**
     * A module is a directory up to two levels deep, or the root.
     *
     * @return void
     */
    public function testCountsModulesTwoLevelsDeep(): void
    {
        $diff  = $this->fixtures->addedDiff("Src/Library/Sync/A.php", 1, ReviewEffortDiffFixtures::PLAIN_CODE);
        $diff .= $this->fixtures->addedDiff("Src/Library/B.php", 1, ReviewEffortDiffFixtures::PLAIN_CODE);
        $diff .= $this->fixtures->addedDiff("README.md", 1, "text");

        $estimate = $this->estimator->estimateDiff($diff);

        $this->assertSame(["Src/Library", "(root)"], $estimate["modules"]);

    }//end testCountsModulesTwoLevelsDeep()


    /**
     * Each score falls in the heaviest bucket whose minimum it reaches.
     *
     * @return void
     */
    public function testBucketFor(): void
    {
        $expected = [];
        $expected["0"]   = "⚡ Trivial (~5 min)";
        $expected["4.9"] = "⚡ Trivial (~5 min)";
        $expected["5"]   = "☕ Light (~15 min)";
        $expected["15"]  = "🧠 Moderate (~30 min)";
        $expected["35"]  = "🔍 Heavy (~1 h)";
        $expected["70"]  = "🪓 Very heavy (consider splitting)";
        $expected["500"] = "🪓 Very heavy (consider splitting)";

        foreach ($expected as $score => $bucket) {
            $this->assertSame($bucket, $this->estimator->bucketFor((float) $score), "score {$score}");
        }

    }//end testBucketFor()


    /**
     * The rough estimate uses the lines and the number of files.
     *
     * @return void
     */
    public function testEstimateTotals(): void
    {
        $estimate = $this->estimator->estimateTotals(10, 100, 0);

        $this->assertSame(15.0, $estimate["score"]);
        $this->assertSame("🧠 Moderate (~30 min)", $estimate["bucket"]);

    }//end testEstimateTotals()


}//end class
