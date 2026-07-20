<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\VersionBumpCommentBuilder;
use PHPUnit\Framework\TestCase;

class VersionBumpCommentBuilderTest extends TestCase
{
    private VersionBumpCommentBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new VersionBumpCommentBuilder();
    }

    public function testBuildIncludesMarkerAndAllThreeCheckboxes(): void
    {
        $body = $this->builder->build("feat: add dashboard");

        $this->assertStringContainsString(VersionBumpCommentBuilder::MARKER, $body);
        $this->assertStringContainsString("- [ ] " . VersionBumpCommentBuilder::CHECKBOX_MAJOR, $body);
        $this->assertStringContainsString("- [ ] " . VersionBumpCommentBuilder::CHECKBOX_MINOR, $body);
        $this->assertStringContainsString("- [ ] " . VersionBumpCommentBuilder::CHECKBOX_NONE, $body);
        $this->assertStringContainsString("feat: add dashboard", $body);
    }

    public function testBuildCompletionWithoutCommitSha(): void
    {
        $completion = $this->builder->buildCompletion("No version bump");

        $this->assertStringContainsString(VersionBumpCommentBuilder::COMPLETION_MARKER, $completion);
        $this->assertStringContainsString("No version bump applied.", $completion);
        $this->assertStringNotContainsString("Commit:", $completion);
    }

    public function testBuildCompletionWithCommitSha(): void
    {
        $completion = $this->builder->buildCompletion("Minor version bump", "abc1234");

        $this->assertStringContainsString(VersionBumpCommentBuilder::COMPLETION_MARKER, $completion);
        $this->assertStringContainsString("Minor version bump applied.", $completion);
        $this->assertStringContainsString("Commit: `abc1234`", $completion);
    }
}
