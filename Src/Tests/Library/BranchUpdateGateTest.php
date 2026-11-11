<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\BranchUpdateGate;
use PHPUnit\Framework\TestCase;

/**
 * How BranchUpdateGate tells, from the active rules of a pull request's head
 * branch, whether GitHub would let the bot update it.
 */
class BranchUpdateGateTest extends TestCase
{
    /** The rules endpoint of the head branch. */
    private const RULES_URL = "repos/owner/repo/rules/branches/feature/x%2By?per_page=100";

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
     * Starts with a GitHub that answers with nothing.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->github = new FakeGitHub();
        $this->gate   = new BranchUpdateGate();

    }//end setUp()


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
        $this->assertSame(["GET fake-token " . self::RULES_URL], $this->github->requests());

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

        $this->assertSame(
            ["update", "required_linear_history", "pull_request", "code_scanning", "required_signatures"],
            $this->blockingRules()
        );

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


}//end class
