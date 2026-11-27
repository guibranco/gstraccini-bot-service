<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\PullRequestSizeLabeler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PullRequestSizeLabelsTest extends TestCase
{
    private PullRequestSizeLabeler $labeler;

    protected function setUp(): void
    {
        $this->labeler = new PullRequestSizeLabeler();
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

    public function testStaleLabelsIncludeOtherSizeLabels(): void
    {
        $this->assertSame(["size/XXXL", "SIZE/huge"], $this->labeler->staleLabels(["size/XXXL", "SIZE/huge", "size/M"], "size/M"));
    }

    public function testDestinationPathOfAPlainHeader(): void
    {
        $this->assertSame("src/a b.php", $this->labeler->destinationPath("diff --git a/src/a b.php b/src/a b.php"));
        $this->assertNull($this->labeler->destinationPath("diff --git nothing here"));
    }

    public function testHasLabelIsCaseInsensitive(): void
    {
        $this->assertTrue($this->labeler->hasLabel(["SIZE/m"], "size/M"));
        $this->assertFalse($this->labeler->hasLabel(["size/L"], "size/M"));
    }
}
