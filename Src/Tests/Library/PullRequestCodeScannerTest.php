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

    public function testIgnoresPortugueseTodoInMarkdownFile(): void
    {
        $diff = <<<DIFF
        diff --git a/docs/README.md b/docs/README.md
        --- a/docs/README.md
        +++ b/docs/README.md
        @@ -1,1 +1,2 @@
         # Documentation
        +Este comportamento deve funcionar para todo o sistema.

        DIFF;

        $files = $this->scanner->scanDiffForKeywords($diff);

        $this->assertSame([], $files);
    }

    public function testIgnoresMarkdownBulletListStartingWithKeyword(): void
    {
        $diff = <<<DIFF
        diff --git a/docs/CHANGELOG.md b/docs/CHANGELOG.md
        --- a/docs/CHANGELOG.md
        +++ b/docs/CHANGELOG.md
        @@ -1,1 +1,2 @@
         # Changelog
        +* todo o processo foi validado

        DIFF;

        $files = $this->scanner->scanDiffForKeywords($diff);

        $this->assertSame([], $files);
    }

    public function testIgnoresTextFile(): void
    {
        $diff = <<<DIFF
        diff --git a/notes.txt b/notes.txt
        --- a/notes.txt
        +++ b/notes.txt
        @@ -1,1 +1,2 @@
         Notes
        +// TODO: this should not be flagged in a text file

        DIFF;

        $files = $this->scanner->scanDiffForKeywords($diff);

        $this->assertSame([], $files);
    }

    public function testDetectsKeywordsAcrossMultipleSupportedCodeFormats(): void
    {
        $diff = <<<DIFF
        diff --git a/src/example.cs b/src/example.cs
        --- a/src/example.cs
        +++ b/src/example.cs
        @@ -1,1 +1,2 @@
         class Example {}
        +// TODO: refactor this method

        DIFF;

        $files = $this->scanner->scanDiffForKeywords($diff);

        $this->assertArrayHasKey("src/example.cs", $files);

        $diff = <<<DIFF
        diff --git a/src/example.py b/src/example.py
        --- a/src/example.py
        +++ b/src/example.py
        @@ -1,1 +1,2 @@
         def example(): pass
        +# FIXME: handle this edge case

        DIFF;

        $files = $this->scanner->scanDiffForKeywords($diff);

        $this->assertArrayHasKey("src/example.py", $files);

        $diff = <<<DIFF
        diff --git a/src/example.go b/src/example.go
        --- a/src/example.go
        +++ b/src/example.go
        @@ -1,1 +1,2 @@
         package main
        +// BUG: this calculation is incorrect

        DIFF;

        $files = $this->scanner->scanDiffForKeywords($diff);

        $this->assertArrayHasKey("src/example.go", $files);
    }
}
