# Track the comments waiting for a user confirmation

Some bot comments on pull requests stop the flow until the user ticks a
checkbox:

- the **version bump** decision (`VersionBumpCommentBuilder`), which keeps
  the "GStraccini Checks: Pull Request" check run in progress until an
  option is picked;
- the **`.infisicalignore` approval**
  (`InfisicalIgnoreApprovalCommentBuilder`).

Their only record was the hidden marker in the comment, so nothing could
list what is waiting on someone, remind them, or tell a forgotten decision
from a settled one without scanning comments through the GitHub API.

## Decision

Each such comment gets a row in `pending_confirmations`. The table is
created by `Sql/0047` in the webhooks repository, which owns the shared
schema until the database split (see webhooks ADR-0002), and mirrored in
`Sql/0017` here. There is one row per bot comment (`CommentId` is unique),
with a `Kind` (`version_bump`, `infisical_ignore`) and a `Status`:

- **pending**: the comment was posted, or an older one was found still
  waiting (`PullRequestsHandler::checkVersionBump`,
  `CommentsHandler::execute_detectInfisicalIgnoreSuggestion`). A row
  resolved as `superseded` is reopened if its comment is found waiting
  again.
- **resolved**: a box was ticked and applied (`major`, `minor`, `none`,
  `applied`, from `CommentsHandler::execute_apply*`), or the decision is no
  longer needed because a `+semver` commit was pushed by hand or the PR no
  longer looks like a feature (`superseded`).
- **cancelled**: the pull request was merged or closed (`merged`,
  `closed`), or the comment was deleted (`comment_deleted`, found by the
  reminder job).

Tracking never breaks the flow it tracks. Handlers go through
`PendingConfirmationTracker`, which logs a database failure to LogStream
(`pending_confirmations` channel) and carries on.

Reminders come from `PendingConfirmationReminder`, a scheduled job of the
comments worker. It runs hourly, with its last run kept in the `settings`
table like the sync jobs. After 3 days pending, and every 3 days after
that, up to 3 times, it mentions the pull request author. Before reminding
it re-reads the PR and the comment from GitHub, so items settled while an
event was missed are closed out instead. An item it can't handle is
retried an interval later, without counting a reminder.

The API lists them with `GET /v1/pending-confirmations/`, taking a
`userId` and an optional `status` (`pending`, `resolved`, `cancelled` or
`all`), scoped to the user's installations like `/pending-actions/`.

New confirmation flows should record their comment with the tracker and
add a `KIND_*` constant.

## Why not `pending_actions`

`pending_actions` is a UI inbox: read or unread, tied to a `notifications`
row, and deleted when the PR closes. A confirmation has a lifecycle
(pending, then resolved or cancelled, with the choice) and reminder
bookkeeping that the bot itself acts on, and it is kept as history once
closed.
