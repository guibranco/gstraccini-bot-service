<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\VersionBumpAnalyzer;
use PHPUnit\Framework\TestCase;

class VersionBumpAnalyzerTest extends TestCase
{
    private VersionBumpAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new VersionBumpAnalyzer();
    }

    public function testLooksLikeFeatureFromTitle(): void
    {
        $this->assertTrue($this->analyzer->looksLikeFeature("feat: add new dashboard", "fix/123", []));
        $this->assertTrue($this->analyzer->looksLikeFeature("Add a new feature", "chore/123", []));
    }

    public function testLooksLikeFeatureFromBranchName(): void
    {
        $this->assertTrue($this->analyzer->looksLikeFeature("Add dashboard", "feature/new-dashboard", []));
        $this->assertTrue($this->analyzer->looksLikeFeature("Add dashboard", "feat/new-dashboard", []));
    }

    public function testLooksLikeFeatureFromLabels(): void
    {
        $this->assertTrue($this->analyzer->looksLikeFeature("Add dashboard", "chore/123", ["enhancement", "feature"]));
    }

    public function testDoesNotLookLikeFeature(): void
    {
        $this->assertFalse($this->analyzer->looksLikeFeature("Fix null pointer bug", "fix/123", ["bug"]));
    }

    public function testDoesNotFalsePositiveOnUnrelatedWords(): void
    {
        $this->assertFalse($this->analyzer->looksLikeFeature("Update featherweight dependency", "chore/deps", []));
    }

    public function testHasSemverDirectiveDetectsMinor(): void
    {
        $this->assertTrue($this->analyzer->hasSemverDirective(["Add check-up job +semver:minor"]));
        $this->assertTrue($this->analyzer->hasSemverDirective(["Some message", "+semver: feature"]));
    }

    public function testHasSemverDirectiveDetectsMajor(): void
    {
        $this->assertTrue($this->analyzer->hasSemverDirective(["Breaking change +semver: major"]));
        $this->assertTrue($this->analyzer->hasSemverDirective(["Breaking change +semver:breaking"]));
    }

    public function testHasSemverDirectiveReturnsFalseWhenAbsent(): void
    {
        $this->assertFalse($this->analyzer->hasSemverDirective(["Add new feature", "Fix typo"]));
    }
}
