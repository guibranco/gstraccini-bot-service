<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\InfisicalIgnoreApprovalCommentBuilder;
use PHPUnit\Framework\TestCase;

class InfisicalIgnoreApprovalCommentBuilderTest extends TestCase
{
    private InfisicalIgnoreApprovalCommentBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new InfisicalIgnoreApprovalCommentBuilder();
    }

    public function testBuildIncludesMarkerWithOriginalCommentId(): void
    {
        $body = $this->builder->build("guibranco", "gstraccini-bot-service", 1112, 987654321);

        $this->assertStringContainsString("<!-- gstraccini-bot:infisicalignore:987654321 -->", $body);
    }

    public function testBuildIncludesPermalinkToOriginalComment(): void
    {
        $body = $this->builder->build("guibranco", "gstraccini-bot-service", 1112, 987654321);

        $this->assertStringContainsString(
            "https://github.com/guibranco/gstraccini-bot-service/pull/1112#issuecomment-987654321",
            $body
        );
    }

    public function testBuildIncludesExactlyOneUncheckedCheckbox(): void
    {
        $body = $this->builder->build("guibranco", "gstraccini-bot-service", 1112, 987654321);

        $this->assertSame(1, preg_match_all("/- \[ \] Apply this suggestion/", $body));
        $this->assertSame(0, preg_match_all("/- \[x\] Apply this suggestion/i", $body));
    }

    public function testBuildCompletionIncludesCommitDetailsAndMarker(): void
    {
        $completion = $this->builder->buildCompletion("abcdef1", "Add suggested .infisicalignore entries");

        $this->assertStringContainsString("<!-- gstraccini-bot:infisicalignore:applied -->", $completion);
        $this->assertStringContainsString("✅ Suggestion successfully applied.", $completion);
        $this->assertStringContainsString("abcdef1", $completion);
    }
}
