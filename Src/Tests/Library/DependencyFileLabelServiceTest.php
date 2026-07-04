<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\DependencyFileLabelService;
use PHPUnit\Framework\TestCase;

class DependencyFileLabelServiceTest extends TestCase
{
    private DependencyFileLabelService $service;

    protected function setUp(): void
    {
        $this->service = new DependencyFileLabelService();
    }

    private function buildDiff(string $filePath, string $eol = "\n"): string
    {
        $lines = [
            "diff --git a/{$filePath} b/{$filePath}",
            "index 111..222 100644",
            "--- a/{$filePath}",
            "+++ b/{$filePath}",
            "@@ -1,1 +1,1 @@",
            "-old line",
            "+new line",
        ];

        return implode($eol, $lines) . $eol;
    }

    public function testDetectsNpmPackageJsonChange(): void
    {
        $result = $this->service->detectDependencyChanges($this->buildDiff("package.json"));

        $this->assertSame(["npm" => "npm/Yarn"], $result);
    }

    public function testDetectsComposerJsonChange(): void
    {
        $result = $this->service->detectDependencyChanges($this->buildDiff("composer.json"));

        $this->assertSame(["composer" => "Composer"], $result);
    }

    public function testDetectsNestedPackageJsonChange(): void
    {
        $result = $this->service->detectDependencyChanges($this->buildDiff("frontend/app/package.json"));

        $this->assertSame(["npm" => "npm/Yarn"], $result);
    }

    public function testDetectsDependencyChangeWithCrlfLineEndings(): void
    {
        $result = $this->service->detectDependencyChanges($this->buildDiff("package.json", "\r\n"));

        $this->assertSame(["npm" => "npm/Yarn"], $result);
    }

    public function testReturnsEmptyArrayWhenNoDependencyFileChanged(): void
    {
        $result = $this->service->detectDependencyChanges($this->buildDiff("src/index.php"));

        $this->assertSame([], $result);
    }

    public function testDetectsMultipleDependencyFilesInSameDiff(): void
    {
        $diff = $this->buildDiff("composer.json") . $this->buildDiff("package.json");

        $result = $this->service->detectDependencyChanges($diff);

        $this->assertSame(
            ["composer" => "Composer", "npm" => "npm/Yarn"],
            $result
        );
    }

    public function testReturnsEmptyArrayForEmptyDiff(): void
    {
        $result = $this->service->detectDependencyChanges("");

        $this->assertSame([], $result);
    }
}
