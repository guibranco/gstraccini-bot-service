<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Tells whether the bot can update a pull request's branch with its base
 * (`PUT /pulls/{number}/update-branch`) before asking GitHub to.
 *
 * `update-branch` adds a merge commit onto the head branch, so any ruleset on
 * that branch that restricts updates, requires a linear history, signed
 * commits, a pull request, or passing checks makes GitHub refuse it with a 422
 * ("Repository rule violations found"). The active rules of the head branch
 * are listed first, and the update is skipped when one of them is in the way.
 *
 * Only rulesets are seen: classic branch protection can't be read without
 * admin rights, and rules the bot is allowed to bypass are still listed. A
 * refused update is still logged by the caller, so those cases stay visible.
 */
class BranchUpdateGate
{
    /** The endpoint listing the active rules of a branch, with the repository and the encoded branch. */
    private const RULES_URL = "repos/%s/rules/branches/%s?per_page=100";


    /**
     * Lists the rules of a branch that would make GitHub refuse to update it.
     *
     * When the rules can't be read the update isn't held back: the caller
     * tries it and logs whatever GitHub answers.
     *
     * @param string $token  The installation token.
     * @param string $repo   The repository the branch is in, as `owner/name`.
     * @param string $branch The branch name.
     *
     * @return string[] The blocking rule types, without duplicates; empty when the branch can be updated.
     */
    public function blockingRules(string $token, string $repo, string $branch): array
    {
        $encodedBranch = implode("/", array_map(rawurlencode(...), explode("/", $branch)));
        $response = doRequestGitHub($token, sprintf(self::RULES_URL, $repo, $encodedBranch), null, "GET");
        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $rules = json_decode($response->getBody());
        if (is_array($rules) === false) {
            return [];
        }

        $blocking = [];
        foreach ($rules as $rule) {
            $type = ($rule->type ?? null);
            if ($this->isBlocking($type) === true && in_array($type, $blocking, true) === false) {
                $blocking[] = $type;
            }
        }

        return $blocking;

    }//end blockingRules()


    /**
     * Tells whether a rule type makes GitHub refuse a merge commit pushed onto the branch.
     *
     * @param string|null $type The rule type.
     *
     * @return boolean
     */
    private function isBlocking(?string $type): bool
    {
        return match ($type) {
            "update", "pull_request", "required_linear_history", "required_signatures", "required_status_checks" => true,
            "code_scanning", "required_deployments", "workflows", "merge_queue" => true,
            default => false,
        };

    }//end isBlocking()


}//end class
