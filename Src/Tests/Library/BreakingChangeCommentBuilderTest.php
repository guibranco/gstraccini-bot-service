<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\BreakingChangeCommentBuilder;
use GuiBranco\GStracciniBot\Library\BreakingChangePlan;
use PHPUnit\Framework\TestCase;

/**
 * How BreakingChangeCommentBuilder asks for, reads and closes the major version bump decision.
 */
class BreakingChangeCommentBuilderTest extends TestCase
{
    private const YES_BOX = "- [ ] Yes, bump the major version";
    private const NO_BOX  = "- [ ] No, keep the current version";


    /**
     * With GitVersion only, an empty commit is announced.
     *
     * @return void
     */
    public function testGitVersionOnlyAsksForAnEmptyCommit(): void
    {
        $plan = new BreakingChangePlan(true, false, null, null, null, null, null);
        $body = (new BreakingChangeCommentBuilder())->build("Drop the v1 API", $plan);

        $this->assertStringStartsWith(BreakingChangeCommentBuilder::MARKER, $body);
        $this->assertStringContainsString("\"Drop the v1 API\"", $body);
        $this->assertStringContainsString("push an empty commit with the GitVersion `+semver: major` directive", $body);
        $this->assertStringNotContainsString("AppVeyor", $body);
        $this->assertStringContainsString(self::YES_BOX, $body);
        $this->assertStringContainsString(self::NO_BOX, $body);

    }//end testGitVersionOnlyAsksForAnEmptyCommit()


    /**
     * With AppVeyor and GitVersion, one commit carries both.
     *
     * @return void
     */
    public function testAppVeyorAndGitVersionShareOneCommit(): void
    {
        $plan = new BreakingChangePlan(true, false, "appveyor.yml", "sha", "1.4.{build}", "2.0.{build}", "version: 2.0.{build}\n");
        $body = (new BreakingChangeCommentBuilder())->build("Drop the v1 API", $plan);

        $this->assertStringContainsString("update the version in `appveyor.yml` from `1.4.{build}` to `2.0.{build}`", $body);
        $this->assertStringContainsString("reset the AppVeyor build number", $body);
        $this->assertStringContainsString("add the GitVersion `+semver: major` directive to that commit", $body);

    }//end testAppVeyorAndGitVersionShareOneCommit()


    /**
     * The ticked box is read; none or both ticked is no choice.
     *
     * @return void
     */
    public function testReadsTheTickedBox(): void
    {
        $builder = new BreakingChangeCommentBuilder();
        $body    = $builder->build("t", new BreakingChangePlan(true, false, null, null, null, null, null));
        $yesBody = str_replace(self::YES_BOX, "- [x] Yes, bump the major version", $body);
        $noBody  = str_replace(self::NO_BOX, "- [X] No, keep the current version", $body);

        $this->assertNull($builder->extractChoice($body));
        $this->assertSame("yes", $builder->extractChoice($yesBody));
        $this->assertSame("no", $builder->extractChoice($noBody));
        $this->assertNull($builder->extractChoice(str_replace(self::NO_BOX, "- [x] No, keep the current version", $yesBody)));

    }//end testReadsTheTickedBox()


    /**
     * The completion lists what was done and the commit.
     *
     * @return void
     */
    public function testCompletionListsWhatWasDone(): void
    {
        $completion = (new BreakingChangeCommentBuilder())->buildCompletion(["AppVeyor build number reset.", "Version bumped."], "abc123");

        $this->assertStringContainsString(BreakingChangeCommentBuilder::COMPLETION_MARKER, $completion);
        $this->assertStringContainsString("✅ AppVeyor build number reset.\n✅ Version bumped.\n", $completion);
        $this->assertStringContainsString("Commit: `abc123`", $completion);

    }//end testCompletionListsWhatWasDone()


}//end class
