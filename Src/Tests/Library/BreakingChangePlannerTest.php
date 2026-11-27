<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\BreakingChangePlan;
use GuiBranco\GStracciniBot\Library\BreakingChangePlanner;
use PHPUnit\Framework\TestCase;

/**
 * How BreakingChangePlanner works out what a breaking change pull request needs.
 */
class BreakingChangePlannerTest extends TestCase
{
    /** The contents endpoint of the repository. */
    private const CONTENTS = "repos/owner/repo/contents/";

    /** The head branch, as it goes in a URL. */
    private const HEAD = "feature%2Fx";

    /**
     * Answers for GitHub.
     *
     * @var FakeGitHub
     */
    private FakeGitHub $gitHub;


    /**
     * Puts a fresh GitHub in use.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->gitHub = new FakeGitHub();

    }//end setUp()


    /**
     * Sets up a file of the repository on a branch.
     *
     * @param string $path    The file path.
     * @param string $ref     The branch, as it goes in a URL.
     * @param string $content The file content.
     *
     * @return void
     */
    private function file(string $path, string $ref, string $content): void
    {
        $file            = [];
        $file["type"]    = "file";
        $file["sha"]     = "sha-{$path}";
        $file["content"] = chunk_split(base64_encode($content), 60, "\n");
        $this->gitHub->answer(sprintf("%s%s?ref=%s", self::CONTENTS, $path, $ref), json_encode($file));

    }//end file()


    /**
     * Plans the bump of a pull request from `feature/x` into `main`.
     *
     * @param string[] $messages The title and commit messages.
     *
     * @return BreakingChangePlan
     */
    private function plan(array $messages): BreakingChangePlan
    {
        return (new BreakingChangePlanner())->plan($this->gitHub->metadata(), "feature/x", "main", $messages);

    }//end plan()


    /**
     * Without GitVersion or AppVeyor there is nothing to do.
     *
     * @return void
     */
    public function testNothingToDoWithoutGitVersionOrAppVeyor(): void
    {
        $plan = $this->plan(["Drop the v1 API"]);

        $this->assertFalse($plan->usesGitVersion);
        $this->assertNull($plan->appVeyorPath);
        $this->assertFalse($plan->isNeeded());

    }//end testNothingToDoWithoutGitVersionOrAppVeyor()


    /**
     * GitVersion needs a major directive, which a `major` or `breaking` one satisfies.
     *
     * @return void
     */
    public function testGitVersionNeedsAMajorDirective(): void
    {
        $this->file("GitVersion.yml", self::HEAD, "mode: ContinuousDelivery\n");

        $plan = $this->plan(["Drop the v1 API", "Add a flag\n\n+semver: minor"]);

        $this->assertTrue($plan->usesGitVersion);
        $this->assertTrue($plan->needsGitVersionBump());
        $this->assertFalse($plan->needsAppVeyorBump());
        $this->assertFalse($this->plan(["Drop it", "Remove the v1 API\n\n+semver: major"])->isNeeded());
        $this->assertFalse($this->plan(["Drop it +semver:breaking"])->isNeeded());

    }//end testGitVersionNeedsAMajorDirective()


    /**
     * AppVeyor needs its major bumped when the branch has the base's.
     *
     * @return void
     */
    public function testAppVeyorNeedsItsMajorBumped(): void
    {
        $this->file("appveyor.yml", self::HEAD, "version: 1.4.{build}\nimage: Ubuntu\n");
        $this->file("appveyor.yml", "main", "version: 1.4.{build}\nimage: Ubuntu\n");

        $plan = $this->plan(["Drop the v1 API"]);

        $this->assertSame("appveyor.yml", $plan->appVeyorPath);
        $this->assertSame("sha-appveyor.yml", $plan->appVeyorFileSha);
        $this->assertSame("1.4.{build}", $plan->appVeyorFrom);
        $this->assertSame("2.0.{build}", $plan->appVeyorTo);
        $this->assertSame("version: 2.0.{build}\nimage: Ubuntu\n", $plan->appVeyorNewContent);
        $this->assertFalse($plan->needsGitVersionBump());

    }//end testAppVeyorNeedsItsMajorBumped()


    /**
     * A major already bumped on the branch needs nothing more.
     *
     * @return void
     */
    public function testAppVeyorAlreadyBumpedOnTheBranch(): void
    {
        $this->file("appveyor.yml", self::HEAD, "version: 2.0.{build}\n");
        $this->file("appveyor.yml", "main", "version: 1.4.{build}\n");

        $plan = $this->plan(["Drop the v1 API"]);

        $this->assertSame("appveyor.yml", $plan->appVeyorPath);
        $this->assertFalse($plan->isNeeded());

    }//end testAppVeyorAlreadyBumpedOnTheBranch()


    /**
     * The dotted AppVeyor file is found too.
     *
     * @return void
     */
    public function testDottedAppVeyorFileIsFound(): void
    {
        $this->file(".appveyor.yml", self::HEAD, "version: 3.1.{build}\n");

        $plan = $this->plan(["Drop the v1 API"]);

        $this->assertSame(".appveyor.yml", $plan->appVeyorPath);
        $this->assertSame("4.0.{build}", $plan->appVeyorTo);

    }//end testDottedAppVeyorFileIsFound()


    /**
     * An AppVeyor file without a numeric version is left alone.
     *
     * @return void
     */
    public function testAppVeyorWithoutANumericVersionIsLeftAlone(): void
    {
        $this->file("appveyor.yml", self::HEAD, "image: Ubuntu\n");

        $plan = $this->plan(["Drop the v1 API"]);

        $this->assertSame("appveyor.yml", $plan->appVeyorPath);
        $this->assertFalse($plan->isNeeded());

    }//end testAppVeyorWithoutANumericVersionIsLeftAlone()


}//end class
