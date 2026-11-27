<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\PullRequestSizeLabeler;
use PHPUnit\Framework\TestCase;

class PullRequestSizeLabelerTest extends TestCase
{
    private PullRequestSizeLabeler $labeler;

    protected function setUp(): void
    {
        $this->labeler = new PullRequestSizeLabeler();
    }

    /**
     * Builds the diff of one file with the given added and removed lines.
     */
    private function buildDiff(string $filePath, array $added, array $removed, string $eol = "\n"): string
    {
        $lines = [
            "diff --git a/{$filePath} b/{$filePath}",
            "index 111..222 100644",
            "--- a/{$filePath}",
            "+++ b/{$filePath}",
            "@@ -1," . count($removed) . " +1," . count($added) . " @@",
            " unchanged context",
        ];
        foreach ($removed as $line) {
            $lines[] = "-{$line}";
        }
        foreach ($added as $line) {
            $lines[] = "+{$line}";
        }

        return implode($eol, $lines) . $eol;
    }

    public function testCountsAdditionsAndDeletions(): void
    {
        $size = $this->labeler->measureDiff($this->buildDiff("src/a.php", ["one", "two", "three"], ["old"]));

        $this->assertSame(3, $size["additions"]);
        $this->assertSame(1, $size["deletions"]);
        $this->assertSame(4, $size["changes"]);
        $this->assertSame([], $size["ignoredFiles"]);
    }

    public function testDoesNotCountFileHeaders(): void
    {
        $size = $this->labeler->measureDiff($this->buildDiff("src/a.php", [], []));

        $this->assertSame(0, $size["changes"]);
    }

    public function testCountsRemovedDashDashLines(): void
    {
        // Removing the SQL comment "-- note" shows as "--- note" inside a hunk.
        $size = $this->labeler->measureDiff($this->buildDiff("Sql/0001.sql", ["++ added"], ["-- note"]));

        $this->assertSame(1, $size["additions"]);
        $this->assertSame(1, $size["deletions"]);
    }

    public function testSkipsGeneratedFiles(): void
    {
        $diff = $this->buildDiff("src/a.php", ["one"], [])
            . $this->buildDiff("package-lock.json", array_fill(0, 500, "lock"), array_fill(0, 200, "lock"))
            . $this->buildDiff("Src/composer.lock", array_fill(0, 50, "lock"), [])
            . $this->buildDiff("dist/app.min.js", ["min"], []);

        $size = $this->labeler->measureDiff($diff);

        $this->assertSame(1, $size["changes"]);
        $this->assertSame(["package-lock.json", "Src/composer.lock", "dist/app.min.js"], $size["ignoredFiles"]);
    }

    public function testCountsDeletedFiles(): void
    {
        $diff = implode("\n", [
            "diff --git a/old.txt b/old.txt",
            "deleted file mode 100644",
            "index 111..000",
            "--- a/old.txt",
            "+++ /dev/null",
            "@@ -1,2 +0,0 @@",
            "-first",
            "-second",
        ]) . "\n";

        $size = $this->labeler->measureDiff($diff);

        $this->assertSame(2, $size["deletions"]);
    }

    public function testIgnoresBinaryAndNoNewline(): void
    {
        $diff = implode("\n", [
            "diff --git a/logo.png b/logo.png",
            "index 111..222 100644",
            "Binary files a/logo.png and b/logo.png differ",
        ]) . "\n" . $this->buildDiff("a.txt", ["x"], ["y"]) . "\\ No newline at end of file\n";

        $size = $this->labeler->measureDiff($diff);

        $this->assertSame(2, $size["changes"]);
    }

    public function testHandlesCrlfLineEndings(): void
    {
        $size = $this->labeler->measureDiff($this->buildDiff("a.txt", ["x", "y"], ["z"], "\r\n"));

        $this->assertSame(3, $size["changes"]);
    }

    public function testEmptyDiffIsZero(): void
    {
        $this->assertSame(0, $this->labeler->measureDiff("")["changes"]);
    }

    public function testDecodesQuotedPaths(): void
    {
        $diff = implode("\n", [
            'diff --git "a/caf\\303\\251 \\"x\\".php" "b/caf\\303\\251 \\"x\\".php"',
            "index 111..222 100644",
            "@@ -0,0 +1,2 @@",
            "+one",
            "+two",
            'diff --git "a/web/\\303\\251/package-lock.json" "b/web/\\303\\251/package-lock.json"',
            "index 111..222 100644",
            "@@ -0,0 +1,1 @@",
            "+lock",
        ]) . "\n";

        $size = $this->labeler->measureDiff($diff);

        $this->assertSame(2, $size["changes"]);
        $this->assertSame(["web/é/package-lock.json"], $size["ignoredFiles"]);
    }
}
