<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\ReviewEffortDiffParser;
use PHPUnit\Framework\TestCase;

/**
 * How ReviewEffortDiffParser reads the files of a diff.
 */
class ReviewEffortDiffParserTest extends TestCase
{
    /** A source file of the repository. */
    private const SOURCE_FILE = "Src/Library/LabelService.php";

    /**
     * The parser under test.
     *
     * @var ReviewEffortDiffParser
     */
    private ReviewEffortDiffParser $parser;

    /**
     * Builds the diffs.
     *
     * @var ReviewEffortDiffFixtures
     */
    private ReviewEffortDiffFixtures $fixtures;


    /**
     * Starts each test with a parser and the fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->parser   = new ReviewEffortDiffParser();
        $this->fixtures = new ReviewEffortDiffFixtures();

    }//end setUp()


    /**
     * The added and deleted lines and the hunks of a file are counted.
     *
     * @return void
     */
    public function testCountsLinesAndHunks(): void
    {
        $added   = $this->fixtures->added(3, "x");
        $removed = $this->fixtures->removed(2, "y");

        $files = $this->parser->parse($this->fixtures->fileDiff(self::SOURCE_FILE, [$added, $removed], []));

        $this->assertCount(1, $files);
        $this->assertSame(self::SOURCE_FILE, $files[0]["path"]);
        $this->assertSame("source", $files[0]["kind"]);
        $this->assertSame("modified", $files[0]["status"]);
        $this->assertSame(3, $files[0]["additions"]);
        $this->assertSame(2, $files[0]["deletions"]);
        $this->assertSame(2, $files[0]["hunks"]);

    }//end testCountsLinesAndHunks()


    /**
     * New, deleted, renamed and binary files are told by their headers.
     *
     * @return void
     */
    public function testReadsTheStatusOfEachFile(): void
    {
        $diff  = $this->fixtures->fileDiff("Src/New.php", [$this->fixtures->added(1, "x")], ["new file mode 100644"]);
        $diff .= $this->fixtures->fileDiff("Src/Old.php", [$this->fixtures->removed(1, "x")], ["deleted file mode 100644"]);
        $diff .= $this->fixtures->renameDiff("Src/Before.php", "Src/After.php");
        $diff .= $this->fixtures->binaryDiff("img/logo.png");

        $statuses = array_column($this->parser->parse($diff), "status", "path");

        $this->assertSame("added", $statuses["Src/New.php"]);
        $this->assertSame("deleted", $statuses["Src/Old.php"]);
        $this->assertSame("renamed", $statuses["Src/After.php"]);
        $this->assertSame("binary", $statuses["img/logo.png"]);

    }//end testReadsTheStatusOfEachFile()


    /**
     * Each branching token of the added code lines is counted.
     *
     * @return void
     */
    public function testCountsBranchingTokens(): void
    {
        $lines   = [];
        $lines[] = "+if (\$a && \$b) {";
        $lines[] = "+foreach (\$x as \$y) {";
        $lines[] = "+} catch (\\Throwable \$e) {";
        $lines[] = "-if (\$old) {";

        $files = $this->parser->parse($this->fixtures->fileDiff(self::SOURCE_FILE, [$lines], []));

        $this->assertSame(4, $files[0]["complexity"]);

    }//end testCountsBranchingTokens()


    /**
     * Branching words in comment lines don't count.
     *
     * @return void
     */
    public function testSkipsBranchingInComments(): void
    {
        $lines   = [];
        $lines[] = "+// if this and that";
        $lines[] = "+ * while waiting";
        $lines[] = "+# for each";
        $lines[] = "+if (\$a) {";

        $files = $this->parser->parse($this->fixtures->fileDiff(self::SOURCE_FILE, [$lines], []));

        $this->assertSame(1, $files[0]["complexity"]);

    }//end testSkipsBranchingInComments()


    /**
     * Branching words in docs don't count.
     *
     * @return void
     */
    public function testSkipsBranchingOutsideCode(): void
    {
        $files = $this->parser->parse($this->fixtures->addedDiff("README.md", 1, "Use it if you want, for fun, while it lasts."));

        $this->assertSame("docs", $files[0]["kind"]);
        $this->assertSame(0, $files[0]["complexity"]);

    }//end testSkipsBranchingOutsideCode()


    /**
     * A diff with Windows line endings reads the same.
     *
     * @return void
     */
    public function testHandlesWindowsLineEndings(): void
    {
        $diff = str_replace("\n", "\r\n", $this->fixtures->addedDiff(self::SOURCE_FILE, 4, ReviewEffortDiffFixtures::BRANCHING_CODE));

        $files = $this->parser->parse($diff);

        $this->assertSame(4, $files[0]["additions"]);
        $this->assertSame(8, $files[0]["complexity"]);

    }//end testHandlesWindowsLineEndings()


    /**
     * Text before the first file, and a diff with no file, give no file.
     *
     * @return void
     */
    public function testIgnoresTextOutsideFiles(): void
    {
        $this->assertSame([], $this->parser->parse(""));
        $this->assertCount(1, $this->parser->parse("preamble\n{$this->fixtures->addedDiff(self::SOURCE_FILE, 1, "x")}"));

    }//end testIgnoresTextOutsideFiles()


}//end class
