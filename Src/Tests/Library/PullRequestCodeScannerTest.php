<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\PullRequestCodeScanner;
use PHPUnit\Framework\TestCase;

class PullRequestCodeScannerTest extends TestCase
{
    private PullRequestCodeScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new PullRequestCodeScanner();
    }

    public function testDetectsTodoCommentInAddedLine(): void
    {
        $diff = <<<DIFF
        diff --git a/src/example.php b/src/example.php
        --- a/src/example.php
        +++ b/src/example.php
        @@ -1,2 +1,3 @@
         <?php
        +// TODO: refactor this method
         echo "hi";

        DIFF;

        $files = $this->scanner->scanDiffForKeywords($diff);

        $this->assertArrayHasKey("src/example.php", $files);
        $this->assertStringContainsString("todo", $files["src/example.php"][0]);
        $this->assertStringContainsString("refactor this method", $files["src/example.php"][0]);
    }

    public function testDetectsFixmeAndBugKeywords(): void
    {
        $diff = <<<DIFF
        diff --git a/src/example.js b/src/example.js
        --- a/src/example.js
        +++ b/src/example.js
        @@ -1,1 +1,3 @@
        +// FIXME: null check missing
        +# bug: race condition here
         console.log("hi");

        DIFF;

        $files = $this->scanner->scanDiffForKeywords($diff);

        $this->assertCount(2, $files["src/example.js"]);
    }

    public function testIgnoresUnrelatedAddedLines(): void
    {
        $diff = <<<DIFF
        diff --git a/src/example.php b/src/example.php
        --- a/src/example.php
        +++ b/src/example.php
        @@ -1,1 +1,2 @@
         <?php
        +echo "nothing interesting here";

        DIFF;

        $files = $this->scanner->scanDiffForKeywords($diff);

        $this->assertSame([], $files);
    }

    public function testGenerateReportForEmptyFindings(): void
    {
        $report = $this->scanner->generateReport([]);

        $this->assertSame(
            "No 'bug', 'fixme' or 'todo' comments found in the pull request.",
            $report
        );
    }

    public function testGenerateReportListsFilesAndLines(): void
    {
        $report = $this->scanner->generateReport([
            "src/example.php" => ["line: 2 - todo: refactor this method"],
        ]);

        $this->assertStringContainsString("src/example.php", $report);
        $this->assertStringContainsString("line: 2 - todo: refactor this method", $report);
    }
}
