<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\BreakingChangeCommitService;
use GuiBranco\GStracciniBot\Library\BreakingChangePlan;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How BreakingChangeCommitService pushes the AppVeyor version bump.
 */
class BreakingChangeCommitServiceTest extends TestCase
{
    /** The AppVeyor file endpoint. */
    private const FILE_URL = "repos/owner/repo/contents/appveyor.yml";

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
     * The metadata of the comment handler.
     *
     * @return array
     */
    private function metadata(): array
    {
        $metadata               = [];
        $metadata["token"]      = FakeGitHub::TOKEN;
        $metadata["repoPrefix"] = "repos/owner/repo";

        return $metadata;

    }//end metadata()


    /**
     * A plan bumping AppVeyor from 1.4 to 2.0.
     *
     * @param boolean $usesGitVersion Whether the repository also uses GitVersion.
     *
     * @return BreakingChangePlan
     */
    private function appVeyorPlan(bool $usesGitVersion): BreakingChangePlan
    {
        return new BreakingChangePlan($usesGitVersion, false, "appveyor.yml", "blob-sha", "1.4.{build}", "2.0.{build}", "version: 2.0.{build}\n");

    }//end appVeyorPlan()


    /**
     * The AppVeyor file is updated on the branch.
     *
     * @return void
     */
    public function testUpdatesTheAppVeyorFileOnTheBranch(): void
    {
        $this->gitHub->answer(self::FILE_URL, json_encode(["commit" => ["sha" => "new-sha"]]));

        $sha = (new BreakingChangeCommitService())->push($this->metadata(), "feature/x", "head-sha", $this->appVeyorPlan(false));

        $this->assertSame("new-sha", $sha);
        $this->assertSame(["PUT fake-token repos/owner/repo/contents/appveyor.yml"], $this->gitHub->requests());

    }//end testUpdatesTheAppVeyorFileOnTheBranch()


    /**
     * A refused update throws.
     *
     * @return void
     */
    public function testRefusedUpdateThrows(): void
    {
        $this->gitHub->failWith(self::FILE_URL, 409, "conflict");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to update appveyor.yml: [409] conflict");

        (new BreakingChangeCommitService())->push($this->metadata(), "feature/x", "head-sha", $this->appVeyorPlan(true));

    }//end testRefusedUpdateThrows()


    /**
     * Nothing is pushed when nothing is needed.
     *
     * @return void
     */
    public function testNoCommitWhenNothingIsNeeded(): void
    {
        $plan = new BreakingChangePlan(true, true, null, null, null, null, null);

        $this->assertNull((new BreakingChangeCommitService())->push($this->metadata(), "feature/x", "head-sha", $plan));
        $this->assertSame([], $this->gitHub->requests());

    }//end testNoCommitWhenNothingIsNeeded()


}//end class
