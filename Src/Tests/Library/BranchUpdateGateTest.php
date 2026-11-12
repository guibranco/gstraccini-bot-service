<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\BranchUpdateGate;
use GuiBranco\GStracciniBot\Tests\Handlers\LogStreamSpy;
use PHPUnit\Framework\TestCase;

/**
 * How BranchUpdateGate tells, from the active rules of a pull request's head
 * branch, whether GitHub would let the bot update it.
 */
class BranchUpdateGateTest extends TestCase
{
    /** The rules endpoint of the head branch. */
    private const RULES_URL = "repos/owner/repo/rules/branches/feature/x%2By?per_page=100";

    /** GitHub's 403 when rulesets aren't on the repository's plan. */
    private const NOT_ON_PLAN = '{"message":"Upgrade to GitHub Pro or make this repository public."}';

    /** GitHub's 403 when the installation can't read the rules. */
    private const NOT_ACCESSIBLE = '{"message":"Resource not accessible by integration"}';

    /**
     * Answers for GitHub.
     *
     * @var FakeGitHub
     */
    private FakeGitHub $github;

    /**
     * The gate under test.
     *
     * @var BranchUpdateGate
     */
    private BranchUpdateGate $gate;

    /**
     * What the gate sends to LogStream.
     *
     * @var LogStreamSpy
     */
    private LogStreamSpy $logStream;


    /**
     * Starts with a GitHub that answers with nothing.
     *
     * @return void
     */
    protected function setUp(): void
    {
        global $logStream;

        $this->github    = new FakeGitHub();
        $this->gate      = new BranchUpdateGate();
        $this->logStream = new LogStreamSpy();
        $logStream       = $this->logStream;

    }//end setUp()


    /**
     * Stops logging to the spy.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        global $logStream;

        $logStream = null;

    }//end tearDown()


    /**
     * Sets up the active rules of the branch.
     *
     * @param string ...$types The rule types.
     *
     * @return void
     */
    private function rules(string ...$types): void
    {
        $rules = array_map(fn (string $type) => ["type" => $type, "ruleset_id" => 1], $types);
        $this->github->answer(self::RULES_URL, json_encode($rules));

    }//end rules()


    /**
     * Lists the blocking rules of the branch the tests work on.
     *
     * @return array
     */
    private function blockingRules(): array
    {
        return $this->gate->blockingRules(FakeGitHub::TOKEN, "owner/repo", "feature/x+y");

    }//end blockingRules()


    /**
     * A branch with no rules can be updated, and its name is encoded segment by segment.
     *
     * @return void
     */
    public function testABranchWithoutRulesCanBeUpdated(): void
    {
        $this->rules();

        $this->assertSame([], $this->blockingRules());
        $this->assertSame([sprintf("GET %s %s", FakeGitHub::TOKEN, self::RULES_URL)], $this->github->requests());

    }//end testABranchWithoutRulesCanBeUpdated()


    /**
     * Rules that don't stop a merge commit from being pushed don't block the update.
     *
     * @return void
     */
    public function testRulesThatAllowAMergeCommitDontBlock(): void
    {
        $this->rules("deletion", "non_fast_forward", "creation");

        $this->assertSame([], $this->blockingRules());

    }//end testRulesThatAllowAMergeCommitDontBlock()


    /**
     * The rules from the 422 answer seen in production all block the update, each listed once.
     *
     * @return void
     */
    public function testRulesThatRefuseAMergeCommitBlock(): void
    {
        $this->rules("update", "required_linear_history", "pull_request", "code_scanning", "required_signatures", "deletion", "update");

        $this->assertSame(["update", "required_linear_history", "pull_request", "code_scanning", "required_signatures"], $this->blockingRules());

    }//end testRulesThatRefuseAMergeCommitBlock()


    /**
     * Required status checks alone block the update.
     *
     * @return void
     */
    public function testRequiredStatusChecksBlock(): void
    {
        $this->rules("required_status_checks");

        $this->assertSame(["required_status_checks"], $this->blockingRules());

    }//end testRequiredStatusChecksBlock()


    /**
     * When the rules can't be read the update isn't held back.
     *
     * @return void
     */
    public function testUnreadableRulesDontBlock(): void
    {
        $this->github->fail(self::RULES_URL, 403);

        $this->assertSame([], $this->blockingRules());

    }//end testUnreadableRulesDontBlock()


    /**
     * Rulesets aren't on the plan of private repositories of free accounts: the
     * 403 GitHub answers then is expected, so it isn't logged as an error, nor
     * warned about.
     *
     * @return void
     */
    public function testRulesetsNotOnThePlanAreExpected(): void
    {
        $this->github->fail(self::RULES_URL, 403, self::NOT_ON_PLAN);

        $this->assertSame([], $this->blockingRules());
        $this->assertSame([403], $this->github->expectedStatusCodes(self::RULES_URL));
        $this->assertSame([], $this->logStream->warnings());

    }//end testRulesetsNotOnThePlanAreExpected()


    /**
     * Any other 403 doesn't hold the update back either, but is warned about.
     *
     * @return void
     */
    public function testOtherForbiddenAnswersAreWarned(): void
    {
        $this->github->fail(self::RULES_URL, 403, self::NOT_ACCESSIBLE);

        $this->assertSame([], $this->blockingRules());
        $this->assertSame(
            ["warning: Rules of owner/repo:feature/x+y can't be read (HTTP 403): " . self::NOT_ACCESSIBLE],
            $this->logStream->lines()
        );

    }//end testOtherForbiddenAnswersAreWarned()


}//end class
