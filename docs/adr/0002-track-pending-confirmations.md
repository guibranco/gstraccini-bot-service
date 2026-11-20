# Track the comments waiting for a user confirmation

Some bot comments on pull requests stop the flow until the user ticks a checkbox:

- the **version bump** decision (`VersionBumpCommentBuilder`), which keeps the
  "GStraccini Checks: Pull Request" check run in progress until an option is picked;
- the **`.infisicalignore` approval** (`InfisicalIgnoreApprovalCommentBuilder`).

Their only record was the hidden marker in the comment, so nothing could list what is waiting on
someone, remind them, or tell a forgotten decision from a settled one without scanning comments
through the GitHub API.

## Decision

Each such comment gets a row in `pending_confirmations` (created by `Sql/0047` in the webhooks
repository, which owns the shared schema until the database split, see webhooks ADR-0002; mirrored
in `Sql/0017` here). One row per bot comment (`CommentId` is unique), with a `Kind`
(`version_bump`, `infisical_ignore`) and a `Status`:

| Event | Where | Status / Resolution |
| --- | --- | --- |
| Comment posted, or an older one found still pending | `PullRequestsHandler::checkVersionBump`, `CommentsHandler::execute_detectInfisicalIgnoreSuggestion` | `pending` |
| Box ticked and applied | `CommentsHandler::execute_apply*` | `resolved` / `major`, `minor`, `none`, `applied` |
| `+semver` pushed by hand, or the PR no longer looks like a feature | `PullRequestsHandler::checkVersionBump` | `resolved` / `superseded` |
| Pull request merged or closed | `PullRequestsHandler::handleItem` | `cancelled` / `merged`, `closed` |
| Comment deleted | reminder job | `cancelled` / `comment_deleted` |

- **Tracking never breaks the flow it tracks.** Handlers go through `PendingConfirmationTracker`, which
  logs a database failure to LogStream (`pending_confirmations` channel) and carries on.
- **Reminders** come from `PendingConfirmationReminder`, a scheduled job of the comments worker (hourly,
  its last run kept in the `settings` table like the sync jobs): after 3 days pending, and every 3 days
  after that, up to 3 times, it mentions the pull request author. Before reminding, it re-reads the PR and
  the comment from GitHub, so items settled while an event was missed are closed out instead.
- **Listing:** `GET /v1/pending-confirmations/?userId=…&status=pending|resolved|cancelled|all`, scoped to the
  user's installations like `/pending-actions/`.

New confirmation flows should record their comment with the tracker and add a `KIND_*` constant.

## Why not `pending_actions`

`pending_actions` is a UI inbox (read/unread, tied to a `notifications` row, deleted when the PR closes).
A confirmation has a lifecycle (pending → resolved/cancelled, with the choice) and reminder bookkeeping
that the bot itself acts on, and is kept as history once closed.
