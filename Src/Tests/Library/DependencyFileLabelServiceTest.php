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

    private function buildWorkflowDiff(string $filePath, array $removedLines, array $addedLines): string
    {
        $lines = [
            "diff --git a/{$filePath} b/{$filePath}",
            "index 111..222 100644",
            "--- a/{$filePath}",
            "+++ b/{$filePath}",
            "@@ -1," . count($removedLines) . " +1," . count($addedLines) . " @@",
        ];

        foreach ($removedLines as $removedLine) {
            $lines[] = "-{$removedLine}";
        }

        foreach ($addedLines as $addedLine) {
            $lines[] = "+{$addedLine}";
        }

        return implode("\n", $lines) . "\n";
    }

    public function testDetectsGitHubActionsVersionBumpInWorkflow(): void
    {
        $diff = $this->buildWorkflowDiff(
            ".github/workflows/ci.yml",
            ["      - uses: actions/checkout@v3"],
            ["      - uses: actions/checkout@v4"]
        );

        $result = $this->service->detectDependencyChanges($diff);

        $this->assertSame(["github-actions" => "GitHub Actions"], $result);
    }

    public function testDetectsGitHubActionsVersionBumpInCompositeAction(): void
    {
        $diff = $this->buildWorkflowDiff(
            ".github/actions/setup/action.yml",
            ["  - uses: actions/setup-node@v3.8.0"],
            ["  - uses: actions/setup-node@v4.0.0"]
        );

        $result = $this->service->detectDependencyChanges($diff);

        $this->assertSame(["github-actions" => "GitHub Actions"], $result);
    }

    public function testIgnoresWorkflowChangesThatAreNotVersionBumps(): void
    {
        $diff = $this->buildWorkflowDiff(
            ".github/workflows/ci.yml",
            ["      run: echo old"],
            ["      run: echo new"]
        );

        $result = $this->service->detectDependencyChanges($diff);

        $this->assertSame([], $result);
    }

    public function testIgnoresUnchangedActionReferences(): void
    {
        $diff = $this->buildWorkflowDiff(
            ".github/workflows/ci.yml",
            ["      - uses: actions/checkout@v4", "      run: echo old"],
            ["      - uses: actions/checkout@v4", "      run: echo new"]
        );

        $result = $this->service->detectDependencyChanges($diff);

        $this->assertSame([], $result);
    }
}
