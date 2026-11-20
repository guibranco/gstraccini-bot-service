<?php

namespace GuiBranco\GStracciniBot\Library;

use Throwable;

/**
 * Records and closes out pending confirmations for the handlers. Tracking is
 * bookkeeping: a database failure is logged, never thrown, so the version bump
 * or `.infisicalignore` flow it follows carries on regardless.
 */
class PendingConfirmationTracker
{
    use LogsToStream;

    private const LOG_CHANNEL = "pending_confirmations";

    private PendingConfirmationStore $store;

    public function __construct(?PendingConfirmationStore $store = null)
    {
        $this->store = $store ?? new PendingConfirmationStore();
    }

    /**
     * Records a pending confirmation from the bot comment GitHub returned when it
     * was posted (or found).
     *
     * @param string      $kind              One of the PendingConfirmation::KIND_* constants.
     * @param string      $owner             Repository owner.
     * @param string      $repo              Repository name.
     * @param int         $installationId    The GitHub App installation.
     * @param int         $pullRequestNumber The pull request.
     * @param object|null $comment           The comment as GitHub returns it (id, html_url).
     * @param string|null $title             The pull request title.
     * @param string|null $author            The pull request author's login.
     *
     * @return void
     */
    public function record(
        string $kind,
        string $owner,
        string $repo,
        int $installationId,
        int $pullRequestNumber,
        ?object $comment,
        ?string $title = null,
        ?string $author = null
    ): void {
        $commentId = (int) ($comment->id ?? 0);
        if ($commentId === 0) {
            return;
        }

        $confirmation = new PendingConfirmation(
            0,
            $kind,
            $owner,
            $repo,
            $installationId,
            $pullRequestNumber,
            $commentId,
            $comment->html_url ?? null,
            $title,
            $author
        );

        $this->attempt("record", $confirmation->owner . "/" . $confirmation->repo, $pullRequestNumber, function () use ($confirmation): void {
            $this->store->record($confirmation);
        });
    }

    /**
     * Resolves the pending confirmation of a bot comment once its choice is applied.
     */
    public function resolveByComment(string $owner, string $repo, int $pullRequestNumber, int $commentId, string $resolution): void
    {
        $this->attempt("resolve", "{$owner}/{$repo}", $pullRequestNumber, function () use ($commentId, $resolution): void {
            $this->store->resolveByComment($commentId, $resolution);
        });
    }

    /**
     * Resolves the pending confirmations of one kind on a pull request that no longer need an answer.
     */
    public function supersede(string $owner, string $repo, int $pullRequestNumber, string $kind): void
    {
        $this->attempt("supersede", "{$owner}/{$repo}", $pullRequestNumber, function () use ($owner, $repo, $pullRequestNumber, $kind): void {
            $this->store->resolveForPullRequest($owner, $repo, $pullRequestNumber, $kind, PendingConfirmation::RESOLUTION_SUPERSEDED);
        });
    }

    /**
     * Cancels the pending confirmations of a pull request that was merged or closed.
     */
    public function cancelForClosedPullRequest(string $owner, string $repo, int $pullRequestNumber, bool $merged): void
    {
        $resolution = $merged ? PendingConfirmation::RESOLUTION_MERGED : PendingConfirmation::RESOLUTION_CLOSED;
        $this->attempt("cancel", "{$owner}/{$repo}", $pullRequestNumber, function () use ($owner, $repo, $pullRequestNumber, $resolution): void {
            $this->store->cancelForPullRequest($owner, $repo, $pullRequestNumber, $resolution);
        });
    }

    /**
     * Runs a store call, logging (not throwing) its failure.
     */
    private function attempt(string $operation, string $repository, int $pullRequestNumber, callable $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            $this->logToStream(
                "warning",
                "Could not {operation} the pending confirmation of {repo}#{pr}: {error}",
                ["operation" => $operation, "repo" => $repository, "pr" => $pullRequestNumber, "error" => $e->getMessage()]
            );
        }
    }
}
