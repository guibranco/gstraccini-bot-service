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
 *
 * Rulesets aren't available on private repositories of free accounts: GitHub
 * answers 403 ("Upgrade to GitHub Pro or make this repository public"). That's
 * expected, so it's only logged at debug level and the update goes ahead. Any
 * other 403 (missing permission, for one) is logged as a warning.
 *
 * A pull request GitHub already knows to have conflicts with its base can't be
 * updated either ("merge conflict between base and head"), so it's skipped too.
 * GitHub works `mergeable` out in the background, so it can still be unknown
 * (null) or stale when the bot looks: the update is then tried, and the 422s
 * that only mean the pull request moved on in between (conflicts, or a new head
 * commit pushed meanwhile) are told apart from the ones worth a warning.
 */
class BranchUpdateGate
{
    use LogsToStream;

    /** The status GitHub answers with when it refuses to update the branch. */
    public const UNPROCESSABLE = 422;

    /** How GitHub's 422 starts when the head conflicts with the base. */
    private const CONFLICT_MESSAGE = "merge conflict between base and head";

    /** How GitHub's 422 starts when the head moved since the pull request was read. */
    private const HEAD_MOVED_MESSAGE = "expected head sha didn't match current head ref";

    /** The LogStream category the warnings go to. */
    private const LOG_CHANNEL = "github";

    /** The endpoint listing the active rules of a branch, with the repository and the encoded branch. */
    private const RULES_URL = "repos/%s/rules/branches/%s?per_page=100";

    /** The status GitHub answers with when the rules can't be read. */
    private const FORBIDDEN = 403;

    /** How GitHub's 403 starts when rulesets aren't on the repository's plan. */
    private const NOT_ON_PLAN_MESSAGE = "Upgrade to GitHub Pro";

    /** What is logged when the rules can't be read for another reason. */
    private const FORBIDDEN_WARNING = "Rules of {repo}:{branch} can't be read (HTTP 403): {body}";


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
        $url = sprintf(self::RULES_URL, $repo, $encodedBranch);
        $response = doRequestGitHubExpecting($token, $url, "GET", [self::FORBIDDEN]);
        $body = (string) $response->getBody();
        if ($response->getStatusCode() === self::FORBIDDEN && $this->isNotOnPlan($body) === false) {
            $this->logToStream("warning", self::FORBIDDEN_WARNING, compact("repo", "branch", "body"));
        }

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
     * Tells whether GitHub already found the pull request to conflict with its base.
     *
     * Only a definite answer counts: while GitHub is still working it out
     * (`mergeable` null, `mergeable_state` unknown) the update is tried.
     *
     * @param object $pullRequest The pull request, as GitHub returns it.
     *
     * @return boolean
     */
    public function hasConflicts(object $pullRequest): bool
    {
        return ($pullRequest->mergeable ?? null) === false
            || ($pullRequest->mergeable_state ?? null) === "dirty";

    }//end hasConflicts()


    /**
     * Tells whether GitHub refused the update only because the pull request
     * changed since it was read: its head conflicts with the base, or a new
     * head commit was pushed. Nothing is wrong with the bot then, and the
     * next event on the pull request picks it up again.
     *
     * @param integer     $statusCode The status of GitHub's answer to the update.
     * @param string|null $body       The body of GitHub's answer.
     *
     * @return boolean
     */
    public function isExpectedRefusal(int $statusCode, ?string $body): bool
    {
        if ($statusCode !== self::UNPROCESSABLE) {
            return false;
        }

        $message = strtolower((json_decode((string) $body)->message ?? ""));

        return str_starts_with($message, self::CONFLICT_MESSAGE)
            || str_starts_with($message, self::HEAD_MOVED_MESSAGE);

    }//end isExpectedRefusal()


    /**
     * Tells whether a 403 from the rules endpoint only means rulesets aren't on the repository's plan.
     *
     * @param string $body The response body.
     *
     * @return boolean
     */
    private function isNotOnPlan(string $body): bool
    {
        $message = (json_decode($body)->message ?? "");

        return str_starts_with($message, self::NOT_ON_PLAN_MESSAGE);

    }//end isNotOnPlan()


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
