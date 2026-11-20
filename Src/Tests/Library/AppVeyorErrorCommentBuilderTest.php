<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\AppVeyorErrorCommentBuilder;
use PHPUnit\Framework\TestCase;

class AppVeyorErrorCommentBuilderTest extends TestCase
{
    private const GITHUB_UNAUTHORIZED_BODY =
        '{"message":"Error reading PR #344 details from GitHub: HTTP GET to ' .
        'https://api.github.com/repos/GuilhermeStracini/POC-dotnet-Asterisk/pulls/344 returned status code ' .
        '401: Unauthorized\nFull response body:\n{\r\n  \"message\": \"Bad credentials\",\r\n  ' .
        '\"documentation_url\": \"https://docs.github.com/rest\",\r\n  \"status\": \"401\"\r\n}"}';

    private AppVeyorErrorCommentBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new AppVeyorErrorCommentBuilder();
    }

    public function testUnwrapsAppVeyorMessageAsQuote(): void
    {
        $comment = $this->builder->build("build", 500, self::GITHUB_UNAUTHORIZED_BODY);

        $this->assertStringStartsWith("AppVeyor build failed: :x:", $comment);
        $this->assertStringContainsString("**AppVeyor response:** HTTP 500", $comment);
        $this->assertStringContainsString(
            "> Error reading PR #344 details from GitHub: HTTP GET to " .
            "https://api.github.com/repos/GuilhermeStracini/POC-dotnet-Asterisk/pulls/344 " .
            "returned status code 401: Unauthorized",
            $comment
        );
        $this->assertStringNotContainsString("> Full response body", $comment);
    }

    public function testSummarisesNestedGitHubResponse(): void
    {
        $comment = $this->builder->build("build", 500, self::GITHUB_UNAUTHORIZED_BODY);

        $this->assertStringContainsString(
            "**Upstream response:** HTTP 401 - Bad credentials ([docs](https://docs.github.com/rest))",
            $comment
        );
    }

    public function testHintsGitHubAuthExpiredOnUpstream401(): void
    {
        $comment = $this->builder->build("build", 500, self::GITHUB_UNAUTHORIZED_BODY);

        $this->assertStringContainsString(":bulb: AppVeyor could not authenticate to GitHub.", $comment);
    }

    public function testKeepsRawBodyInCollapsedDetails(): void
    {
        $comment = $this->builder->build("build", 500, self::GITHUB_UNAUTHORIZED_BODY);

        $this->assertStringContainsString("<details>\r\n<summary>Raw response</summary>", $comment);
        $this->assertStringContainsString(self::GITHUB_UNAUTHORIZED_BODY, $comment);
    }

    public function testPlainMessageWithoutNestedResponse(): void
    {
        $comment = $this->builder->build("registration", 400, '{"message":"Project already exists."}');

        $this->assertStringStartsWith("AppVeyor registration failed: :x:", $comment);
        $this->assertStringContainsString("> Project already exists.", $comment);
        $this->assertStringNotContainsString("**Upstream response:**", $comment);
        $this->assertStringNotContainsString(":bulb:", $comment);
    }

    public function testNonJsonBodyIsQuotedAsIs(): void
    {
        $comment = $this->builder->build("bump version", 502, "Bad Gateway");

        $this->assertStringContainsString("**AppVeyor response:** HTTP 502", $comment);
        $this->assertStringContainsString("> Bad Gateway", $comment);
    }

    public function testEmptyBody(): void
    {
        $comment = $this->builder->build("update next build number", 500, "");

        $this->assertStringNotContainsString("> ", $comment);
        $this->assertStringContainsString("(empty response body)", $comment);
    }

    public function testHintsApiKeyWhenAppVeyorRejectsBot(): void
    {
        $comment = $this->builder->build("build", 401, '{"message":"Authorization required."}');

        $this->assertStringContainsString(":bulb: AppVeyor rejected the bot's API key.", $comment);
    }

    public function testHintsRegistrationWhenNotFound(): void
    {
        $comment = $this->builder->build("bump version", 404, '{"message":"Project not found or access denied."}');

        $this->assertStringContainsString(":bulb: AppVeyor could not find the project.", $comment);
    }

    public function testRawBodyCannotCloseTheCodeFence(): void
    {
        $comment = $this->builder->build("build", 500, "oops ``` injected");

        $this->assertSame(2, substr_count($comment, "```"));
    }
}
