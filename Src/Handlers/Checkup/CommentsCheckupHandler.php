<?php

namespace GuiBranco\GStracciniBot\Handlers\Checkup;

/**
 * Extension point for verifying that "required" comments exist on open
 * pull requests / issues (e.g. bot marker comments that should always be
 * present once a given condition is met).
 *
 * Not implemented yet — the rules for what counts as a "required" comment
 * haven't been defined. Wired into the orchestrator as a no-op so the rest
 * of the checkup can run today, and so this is the single place to fill in
 * once the rules exist.
 */
class CommentsCheckupHandler
{
    /**
     * @return array{checked: int, backfilled: int, skipped: bool}
     */
    public function check(object $pullRequestOrIssueContext): array
    {
        return ["checked" => 0, "backfilled" => 0, "skipped" => true];
    }
}
