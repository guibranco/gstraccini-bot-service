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

    private const LOG_CHANNEL = 'pending_confirmations';

    /**
     * Where the confirmations are kept.
     *
     * @var PendingConfirmationStore
     */
    private PendingConfirmationStore $store;


    /**
     * Sets up the tracker.
     *
     * @param PendingConfirmationStore|null $store Where the confirmations are kept (default: the database).
     *
     * @return void
     */
    public function __construct(?PendingConfirmationStore $store=null)
    {
        $this->store = ($store ?? new PendingConfirmationStore());

    }//end __construct()


    /**
     * Records a pending confirmation from the bot comment GitHub returned when it
     * was posted (or found).
     *
     * @param string      $kind              One of the PendingConfirmation::KIND_* constants.
     * @param string      $owner             Repository owner.
     * @param string      $repo              Repository name.
     * @param integer     $installationId    The GitHub App installation.
     * @param integer     $pullRequestNumber The pull request.
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
        ?string $title=null,
        ?string $author=null
    ): void {
        $commentId = (int) ($comment->id ?? 0);
        if ($commentId === 0) {
            return;
        }

        $confirmation         = new PendingConfirmation(
            0,
            $kind,
            $owner,
            $repo,
            $installationId,
            $pullRequestNumber,
            $commentId,
            ($comment->html_url ?? null)
        );
        $confirmation->title  = $title;
        $confirmation->author = $author;

        $this->attempt(
            'record',
            "{$confirmation->owner}/{$confirmation->repo}",
            $pullRequestNumber,
            function () use ($confirmation): void {
                $this->store->record($confirmation);
            }
        );

    }//end record()


    /**
     * Resolves the pending confirmation of a bot comment once its choice is applied.
     *
     * @param string  $owner             Repository owner.
     * @param string  $repo              Repository name.
     * @param integer $pullRequestNumber The pull request.
     * @param integer $commentId         The bot's comment.
     * @param string  $resolution        What was decided.
     *
     * @return void
     */
    public function resolveByComment(string $owner, string $repo, int $pullRequestNumber, int $commentId, string $resolution): void
    {
        $this->attempt(
            'resolve',
            "{$owner}/{$repo}",
            $pullRequestNumber,
            function () use ($commentId, $resolution): void {
                $this->store->resolveByComment($commentId, $resolution);
            }
        );

    }//end resolveByComment()


    /**
     * Resolves the pending confirmations of one kind on a pull request that no longer need an answer.
     *
     * @param string  $owner             Repository owner.
     * @param string  $repo              Repository name.
     * @param integer $pullRequestNumber The pull request.
     * @param string  $kind              One of the PendingConfirmation::KIND_* constants.
     *
     * @return void
     */
    public function supersede(string $owner, string $repo, int $pullRequestNumber, string $kind): void
    {
        $this->attempt(
            'supersede',
            "{$owner}/{$repo}",
            $pullRequestNumber,
            function () use ($owner, $repo, $pullRequestNumber, $kind): void {
                $this->store->resolveForPullRequest($owner, $repo, $pullRequestNumber, $kind, PendingConfirmation::RESOLUTION_SUPERSEDED);
            }
        );

    }//end supersede()


    /**
     * Cancels the pending confirmations of a pull request that was merged or closed.
     *
     * @param string  $owner             Repository owner.
     * @param string  $repo              Repository name.
     * @param integer $pullRequestNumber The pull request.
     * @param boolean $merged            Whether it was merged (or just closed).
     *
     * @return void
     */
    public function cancelForClosedPullRequest(string $owner, string $repo, int $pullRequestNumber, bool $merged): void
    {
        $resolution = PendingConfirmation::RESOLUTION_CLOSED;
        if ($merged === true) {
            $resolution = PendingConfirmation::RESOLUTION_MERGED;
        }

        $this->attempt(
            'cancel',
            "{$owner}/{$repo}",
            $pullRequestNumber,
            function () use ($owner, $repo, $pullRequestNumber, $resolution): void {
                $this->store->cancelForPullRequest($owner, $repo, $pullRequestNumber, $resolution);
            }
        );

    }//end cancelForClosedPullRequest()


    /**
     * Runs a store call, logging (not throwing) its failure.
     *
     * @param string   $operation         What is being done, for the log.
     * @param string   $repository        The `owner/name` of the repository.
     * @param integer  $pullRequestNumber The pull request.
     * @param callable $call              The store call.
     *
     * @return void
     */
    private function attempt(string $operation, string $repository, int $pullRequestNumber, callable $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            $context              = [];
            $context['operation'] = $operation;
            $context['repo']      = $repository;
            $context['pr']        = $pullRequestNumber;
            $context['error']     = $e->getMessage();
            $this->logToStream('warning', 'Could not {operation} the pending confirmation of {repo}#{pr}: {error}', $context);
        }

    }//end attempt()


}//end class
