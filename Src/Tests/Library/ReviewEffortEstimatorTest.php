<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\ReviewEffortEstimator;
use PHPUnit\Framework\TestCase;

/**
 * How ReviewEffortEstimator scores the files of a pull request.
 */
class ReviewEffortEstimatorTest extends TestCase
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
     * A large lock file bump is trivial: generated files don't count.
     *
     * @return void
     */
    public function testGeneratedFilesDoNotCount(): void
    {
        $estimate = $this->estimator->estimateDiff($this->fixtures->addedDiff("package-lock.json", 2000, '"x": "1.0.0",'));

        $this->assertSame(0.0, $estimate["score"]);
        $this->assertSame(["package-lock.json"], $estimate["ignoredFiles"]);
        $this->assertSame([], $estimate["files"]);
        $this->assertSame("⚡ Trivial (~5 min)", $estimate["bucket"]);

    }//end testGeneratedFilesDoNotCount()


    /**
     * 40 branching lines in 8 hunks of 4 modules outweigh 300 plain lines in one block.
     *
     * @return void
     */
    public function testScatteredChangeOutweighsOneBlock(): void
    {
        $block = $this->estimator->estimateDiff($this->fixtures->addedDiff(self::SOURCE_FILE, 300, ReviewEffortDiffFixtures::PLAIN_CODE));

        $hunk      = $this->fixtures->added(5, ReviewEffortDiffFixtures::BRANCHING_CODE);
        $scattered = "";
        foreach (["Src/Handlers/A.php", "Src/Library/B.php", "Src/lib/c.php", "Src/config/d.php"] as $path) {
            $scattered .= $this->fixtures->fileDiff($path, [$hunk, $hunk], []);
        }

        $estimate = $this->estimator->estimateDiff($scattered);

        $this->assertGreaterThan($block["score"], $estimate["score"]);
        $this->assertSame(8, $estimate["hunks"]);
        $this->assertSame(80, $estimate["complexity"]);
        $this->assertCount(4, $estimate["modules"]);

    }//end testScatteredChangeOutweighsOneBlock()


    /**
     * The same lines weigh less in docs than in source.
     *
     * @return void
     */
    public function testDocsWeighLessThanSource(): void
    {
        $docs   = $this->estimator->estimateDiff($this->fixtures->addedDiff("docs/guide.md", 100, "Some text."));
        $source = $this->estimator->estimateDiff($this->fixtures->addedDiff(self::SOURCE_FILE, 100, ReviewEffortDiffFixtures::PLAIN_CODE));

        $this->assertLessThan($source["score"], $docs["score"]);

    }//end testDocsWeighLessThanSource()


    /**
     * A sensitive path multiplies the score.
     *
     * @return void
     */
    public function testSensitivePathsRaiseTheScore(): void
    {
        $plain    = $this->estimator->estimateDiff($this->fixtures->addedDiff(self::SOURCE_FILE, 50, ReviewEffortDiffFixtures::PLAIN_CODE));
        $risky    = $this->estimator->estimateDiff($this->fixtures->addedDiff("Src/Library/TokenStore.php", 50, ReviewEffortDiffFixtures::PLAIN_CODE));
        $expected = ($plain["score"] * ReviewEffortEstimator::RISK_MULTIPLIER);

        $this->assertSame([], $plain["riskyFiles"]);
        $this->assertSame(["Src/Library/TokenStore.php"], $risky["riskyFiles"]);
        $this->assertEqualsWithDelta($expected, $risky["score"], 0.11);

    }//end testSensitivePathsRaiseTheScore()


    /**
     * Deleting a whole file weighs little.
     *
     * @return void
     */
    public function testDeletedFilesWeighLittle(): void
    {
        $removed = $this->fixtures->removed(400, ReviewEffortDiffFixtures::PLAIN_CODE);

        $estimate = $this->estimator->estimateDiff($this->fixtures->fileDiff("Src/Old.php", [$removed], ["deleted file mode 100644"]));

        $this->assertSame("deleted", $estimate["files"][0]["status"]);
        $this->assertLessThan(7.0, $estimate["score"]);

    }//end testDeletedFilesWeighLittle()


    /**
     * Moving a file without changes weighs nothing.
     *
     * @return void
     */
    public function testPureRenameWeighsNothing(): void
    {
        $estimate = $this->estimator->estimateDiff($this->fixtures->renameDiff("Src/Old.php", "Src/New.php"));

        $this->assertSame("renamed", $estimate["files"][0]["status"]);
        $this->assertSame(0.0, $estimate["score"]);

    }//end testPureRenameWeighsNothing()


    /**
     * A binary file has a fixed score.
     *
     * @return void
     */
    public function testBinaryFilesHaveAFixedScore(): void
    {
        $estimate = $this->estimator->estimateDiff($this->fixtures->binaryDiff("img/logo.png"));

        $this->assertSame(ReviewEffortEstimator::BINARY_POINTS, $estimate["score"]);

    }//end testBinaryFilesHaveAFixedScore()


    /**
     * The files are listed from the heaviest.
     *
     * @return void
     */
    public function testFilesAreSortedFromTheHeaviest(): void
    {
        $diff  = $this->fixtures->addedDiff("README.md", 1, "text");
        $diff .= $this->fixtures->addedDiff(self::SOURCE_FILE, 50, ReviewEffortDiffFixtures::PLAIN_CODE);

        $estimate = $this->estimator->estimateDiff($diff);

        $this->assertSame([self::SOURCE_FILE, "README.md"], array_column($estimate["files"], "path"));

    }//end testFilesAreSortedFromTheHeaviest()


}//end class
