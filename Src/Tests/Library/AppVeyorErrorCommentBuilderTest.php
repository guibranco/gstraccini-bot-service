<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\AppVeyorErrorCommentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * How AppVeyorErrorCommentBuilder turns failed AppVeyor responses into comments.
 */
class AppVeyorErrorCommentBuilderTest extends TestCase
{
    /** AppVeyor's answer when its own GitHub credentials are rejected (from a real PR comment). */
    private const GITHUB_UNAUTHORIZED = '{"message":"Error reading PR #344 details from GitHub: HTTP GET to https://api.github.com/repos/GuilhermeStracini/POC-dotnet-Asterisk/pulls/344 returned status code 401: Unauthorized\nFull response body:\n{\r\n  \"message\": \"Bad credentials\",\r\n  \"documentation_url\": \"https://docs.github.com/rest\",\r\n  \"status\": \"401\"\r\n}"}';

    /** AppVeyor's message, without the upstream response. */
    private const GITHUB_SUMMARY = "> Error reading PR #344 details from GitHub: HTTP GET to https://api.github.com/repos/GuilhermeStracini/POC-dotnet-Asterisk/pulls/344 returned status code 401: Unauthorized";

    /**
     * The builder under test.
     *
     * @var AppVeyorErrorCommentBuilder
     */
    private AppVeyorErrorCommentBuilder $builder;


    /**
     * Creates the builder.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->builder = new AppVeyorErrorCommentBuilder();

    }//end setUp()


    /**
     * The message inside AppVeyor's envelope is quoted, without the upstream body.
     *
     * @return void
     */
    public function testUnwrapsAppVeyorMessageAsQuote(): void
    {
        $comment = $this->builder->build("build", 500, self::GITHUB_UNAUTHORIZED);

        $this->assertStringStartsWith("AppVeyor build failed: :x:", $comment);
        $this->assertStringContainsString("**AppVeyor response:** HTTP 500", $comment);
        $this->assertStringContainsString(self::GITHUB_SUMMARY, $comment);
        $this->assertStringNotContainsString("> Full response body", $comment);

    }//end testUnwrapsAppVeyorMessageAsQuote()


    /**
     * GitHub's response embedded in AppVeyor's message is summarised on one line.
     *
     * @return void
     */
    public function testSummarisesNestedGitHubResponse(): void
    {
        $comment = $this->builder->build("build", 500, self::GITHUB_UNAUTHORIZED);

        $this->assertStringContainsString("**Upstream response:** HTTP 401 - Bad credentials ([docs](https://docs.github.com/rest))", $comment);

    }//end testSummarisesNestedGitHubResponse()


    /**
     * A 401 from GitHub means AppVeyor's own GitHub authorization is gone.
     *
     * @return void
     */
    public function testHintsGitHubAuthExpiredOnUpstream401(): void
    {
        $comment = $this->builder->build("build", 500, self::GITHUB_UNAUTHORIZED);

        $this->assertStringContainsString(":bulb: AppVeyor could not authenticate to GitHub.", $comment);

    }//end testHintsGitHubAuthExpiredOnUpstream401()


    /**
     * The raw body stays available in a collapsed block.
     *
     * @return void
     */
    public function testKeepsRawBodyInCollapsedDetails(): void
    {
        $comment = $this->builder->build("build", 500, self::GITHUB_UNAUTHORIZED);

        $this->assertStringContainsString("<details>\r\n<summary>Raw response</summary>", $comment);
        $this->assertStringContainsString(self::GITHUB_UNAUTHORIZED, $comment);

    }//end testKeepsRawBodyInCollapsedDetails()


    /**
     * A message with no upstream response gets neither a summary nor a hint.
     *
     * @return void
     */
    public function testPlainMessageWithoutNestedResponse(): void
    {
        $comment = $this->builder->build("registration", 400, '{"message":"Project already exists."}');

        $this->assertStringStartsWith("AppVeyor registration failed: :x:", $comment);
        $this->assertStringContainsString("> Project already exists.", $comment);
        $this->assertStringNotContainsString("**Upstream response:**", $comment);
        $this->assertStringNotContainsString(":bulb:", $comment);

    }//end testPlainMessageWithoutNestedResponse()


    /**
     * A body that isn't JSON is quoted as it is, and an empty one is labelled.
     *
     * @return void
     */
    public function testNonJsonAndEmptyBodies(): void
    {
        $nonJson = $this->builder->build("bump version", 502, "Bad Gateway");
        $empty   = $this->builder->build("update next build number", 500, "");

        $this->assertStringContainsString("**AppVeyor response:** HTTP 502", $nonJson);
        $this->assertStringContainsString("> Bad Gateway", $nonJson);
        $this->assertStringNotContainsString("> ", $empty);
        $this->assertStringContainsString("(empty response body)", $empty);

    }//end testNonJsonAndEmptyBodies()


    /**
     * A 401 from AppVeyor itself points at the bot's API key.
     *
     * @return void
     */
    public function testHintsApiKeyWhenAppVeyorRejectsBot(): void
    {
        $comment = $this->builder->build("build", 401, '{"message":"Authorization required."}');

        $this->assertStringContainsString(":bulb: AppVeyor rejected the bot's API key.", $comment);

    }//end testHintsApiKeyWhenAppVeyorRejectsBot()


    /**
     * A 404 from AppVeyor points at the project registration.
     *
     * @return void
     */
    public function testHintsRegistrationWhenNotFound(): void
    {
        $comment = $this->builder->build("bump version", 404, '{"message":"Project not found or access denied."}');

        $this->assertStringContainsString(":bulb: AppVeyor could not find the project.", $comment);

    }//end testHintsRegistrationWhenNotFound()


    /**
     * Backticks in the body can't close the code fence early.
     *
     * @return void
     */
    public function testRawBodyCannotCloseTheCodeFence(): void
    {
        $comment = $this->builder->build("build", 500, "oops ``` injected");

        $this->assertSame(2, substr_count($comment, "```"));

    }//end testRawBodyCannotCloseTheCodeFence()


}//end class
