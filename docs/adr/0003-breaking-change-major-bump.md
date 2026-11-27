# Bump the major version of breaking change pull requests

The pull request template asks "☢️ Does this introduce a breaking change?"
with a Yes/No checkbox pair. The description check already makes sure
exactly one of them is ticked, but nothing acted on the answer: the
`breaking-changes` label was added by hand, if at all, and the major version
was bumped by hand in `appveyor.yml` or with a `+semver: major` commit, or
forgotten.

## Decision

`PullRequestsHandler::checkBreakingChanges` reads the group
(`BreakingChangeDetector`, matching any heading that mentions a breaking
change) on every pull request run:

- **Yes**: the `breaking-changes` label is added (`☢️ breaking-changes` when
  the repository has its labels with icons). **No** removes it. An unclear
  answer leaves the labels alone; the description check reports it.
- With **Yes**, when the "request version bump" setting is on
  (`FeatureSettings::REQUEST_VERSION_BUMP`, the same opt-in as the version
  bump decision) and the pull request isn't from a fork,
  `BreakingChangePlanner` looks at the head branch:
  - **GitVersion** (`GitVersion.yml`): a commit or the title must carry
    `+semver: major` or `+semver: breaking`;
  - **AppVeyor** (`appveyor.yml` / `.appveyor.yml`): the major of the
    top-level `version:` must be bigger than on the base branch.

  If something is missing, the bot posts one comment
  (`BreakingChangeCommentBuilder`) with "Yes, bump the major version" / "No,
  keep the current version", tracked as a pending confirmation of kind
  `breaking_change` (ADR-0002).
- When **Yes** is ticked, `CommentsHandler::execute_applyBreakingChangeDecision`
  resets the AppVeyor build number first (so the new commit builds with it),
  then pushes a **single** commit (`BreakingChangeCommitService`): the
  `appveyor.yml` update when AppVeyor is used, with `+semver: major` in its
  message when GitVersion is used too, or an empty `+semver: major` commit
  for GitVersion alone. The pull request is then re-queued like the version
  bump commit.

## Consequences

- No new setting or table: the label is always managed, the question rides
  on "request version bump", and `pending_confirmations.Kind` is free text.
- Unlike the version bump decision, this question never holds the
  "GStraccini Checks: Pull Request" check run: the author already answered
  the breaking change question in the description, this is only about doing
  the bump for them.
- A feature pull request that is also a breaking change can get both
  questions. Answering "Yes" here pushes `+semver: major`, which settles the
  version bump decision on the next run (it is superseded).
- The AppVeyor reset sets the next build number to 0, like the
  `appveyor reset` command. Without an AppVeyor key stored for the
  installation, the commit is still pushed and the comment says how to reset
  it afterwards.
