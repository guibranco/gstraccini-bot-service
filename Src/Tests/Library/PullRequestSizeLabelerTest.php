<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\PullRequestSizeLabeler;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testCountsRemovedLinesThatLookLikeHeaders(): void
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

    public function testIgnoresBinaryFilesAndNoNewlineMarkers(): void
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

    public static function thresholdProvider(): array
    {
        return [
            [0, "size/XS"],
            [9, "size/XS"],
            [10, "size/S"],
            [29, "size/S"],
            [30, "size/M"],
            [99, "size/M"],
            [100, "size/L"],
            [499, "size/L"],
            [500, "size/XL"],
            [999, "size/XL"],
            [1000, "size/XXL"],
            [25000, "size/XXL"],
        ];
    }

    #[DataProvider('thresholdProvider')]
    public function testLabelFor(int $changes, string $expected): void
    {
        $this->assertSame($expected, $this->labeler->labelFor($changes));
    }

    public function testStaleLabelsAreTheOtherSizeLabels(): void
    {
        $current = ["🐛 bug", "size/S", "Size/XL", "size/M", "sizeable"];

        $this->assertSame(["size/S", "Size/XL"], $this->labeler->staleLabels($current, "size/M"));
    }

    public function testHasLabelIsCaseInsensitive(): void
    {
        $this->assertTrue($this->labeler->hasLabel(["SIZE/m"], "size/M"));
        $this->assertFalse($this->labeler->hasLabel(["size/L"], "size/M"));
    }
}
