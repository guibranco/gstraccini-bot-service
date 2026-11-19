# Installation access tokens are cached and shared per installation

Every handled item used to mint a GitHub App installation token (`POST /app/installations/{id}/access_tokens`),
scoped to the item's repository — one call per push, issue, branch or pull request, up to three per comment.
GitHub tokens live for one hour, so almost all of those calls were redundant.

`generateInstallationAccessToken()` (`Src/lib/github.php`) now goes through
`Library/InstallationTokenCache`, which serves a still-valid token from, in order:

1. **the current PHP process** — the 55-second cron/HTTP loop and the daemon handle many items per process;
2. **APCu**, when the extension is loaded and enabled — shared by the requests one PHP pool serves on the
   cPanel host;
3. **the `github_installation_tokens` table** — shared by every process and host on the same database;

and only mints a new token when none of them has one, writing it back to every layer.

## Decisions

### One token per installation, not per repository

Tokens are no longer scoped to the item's repository: one token covers every repository of the
installation, and the installation is already limited to the repositories its owner granted. This turns
"one token per active repository per hour" into "one token per installation per hour".
`generateInstallationToken()` (repository-scoped) was removed. `requestInstallationAccessToken()` stays
uncached: the sync handlers need its raw 404/422 answers to detect uninstalled installations and removed
repositories, and they run hourly at most.

### Tokens are encrypted in the database, and never stored there in plain text

A token is a bearer credential over the whole installation for an hour. It is stored with `CryptoHelper`
(AES-256-GCM, the key in `secrets/encryption.secrets.php`), like the user integrations' API keys. Without
an encryption key the database layer is skipped; the process and APCu layers still work.

### Expiry is a Unix timestamp, with a 5-minute safety margin

`ExpiresAt` is GitHub's `expires_at` as a Unix timestamp (`BIGINT`), so the PHP (`Europe/Dublin`) and
MySQL time zones can't disagree about it. A token stops being served 5 minutes before it expires, so an
item never starts with a token about to lapse.

### Caching is best effort

An APCu or database failure is logged (LogStream category `installation_token`) and the next layer is
used — eventually minting, as before. The table can therefore be created before or after this code is
deployed. Two processes minting the same token at once is harmless: the last write wins. Expired rows are
deleted whenever a token is stored.

### A token GitHub rejects is evicted

GitHub can revoke a token before it expires (app uninstalled, permissions changed). When a GitHub API
call answers 401 with a token this process handed out, `requestGitHub()` evicts it from every layer, so
the next item mints a new one.

### The table lives in the webhooks project

Like the other shared tables, `github_installation_tokens` is created by a migration in the `webhooks`
repository, not in `Sql/` here.
