<?php

namespace GuiBranco\GStracciniBot\Library;

use DateTimeImmutable;

/**
 * Asks Dependabot or Depfu to recreate a pull request of theirs that has
 * conflicts, with a `@dependabot recreate` or `@depfu recreate` comment that
 * opens with the marker of the head commit, and looks after the request: one
 * the dependency bot never acknowledged is posted again, a limited number of
 * times (see RecreateRequestAnalyzer).
 */
class RecreateRequester
{
    /** Comments asked for per page, the most GitHub returns. */
    private const COMMENTS_PER_PAGE = 100;

    /** Pages after which a comments listing that never returns a short page is stopped. */
    private const COMMENTS_MAX_PAGES = 30;

    /**
     * Reads where the request stands from the pull request's comments.
     *
     * @var RecreateRequestAnalyzer
     */
    private RecreateRequestAnalyzer $analyzer;


    /**
     * Sets up the reading of the requests already posted.
     */
    public function __construct()
    {
        $this->analyzer = new RecreateRequestAnalyzer();

    }//end __construct()


    /**
     * Posts the recreate request of a conflicting pull request, unless one for
     * its head commit is still waiting for the dependency bot, was acknowledged
     * by it, or was already posted as many times as allowed.
     *
     * @param array             $metadata Metadata for the GitHub API requests. Must include `commentsUrl`,
     *                                    `owner`, `repo`, `token` (to read) and `userToken` (to post as
     *                                    the owner).
     * @param string            $headSha  The pull request's current head commit.
     * @param string            $sender   The pull request's author: `dependabot[bot]` or `depfu[bot]`.
     * @param DateTimeImmutable $now      The moment the request is evaluated at.
     *
     * @return RecreateRequestStatus Where the request stood before this call.
     *
     * @throws \GuiBranco\Pancake\RequestException When a page of comments can't be fetched: a partial list
     *                                             could hide a request that was already posted.
     */
    public function request(array $metadata, string $headSha, string $sender, DateTimeImmutable $now): RecreateRequestStatus
    {
        $status = $this->analyzer->analyze(
            $this->listComments($metadata),
            $headSha,
            $now,
            fn (object $comment): bool => $this->hasReactionFrom($metadata, $comment, $sender)
        );

        if ($status->mustRequest() === true) {
            $this->post($metadata, $headSha, $sender);
        }

        return $status;

    }//end request()


    /**
     * Lists every comment of the pull request, following the pagination: the
     * recreate requests of a long-lived pull request are among its newest
     * comments, past the first page.
     *
     * @param array $metadata Metadata for the GitHub API request. Must include `commentsUrl` and `token`.
     *
     * @return array The comments, oldest first.
     */
    private function listComments(array $metadata): array
    {
        $comments = [];

        for ($page = 1; $page <= self::COMMENTS_MAX_PAGES; $page++) {
            $url = sprintf("%s?per_page=%d&page=%d", $metadata["commentsUrl"], self::COMMENTS_PER_PAGE, $page);
            $response = doRequestGitHub($metadata["token"], $url, null, "GET");
            $response->ensureSuccessStatus();
            $pageComments = json_decode($response->getBody());
            if (is_array($pageComments) === false) {
                break;
            }

            $comments = array_merge($comments, $pageComments);
            if (count($pageComments) < self::COMMENTS_PER_PAGE) {
                break;
            }
        }

        return $comments;

    }//end listComments()


    /**
     * Whether a login is among those who reacted to a comment. The comments
     * listing only carries the reaction totals, and a reaction from someone
     * else is not the dependency bot's acknowledgement of a recreate request.
     *
     * @param array  $metadata Metadata for the GitHub API request. Must include `owner`, `repo` and `token`.
     * @param object $comment  The comment, as the comments listing returns it.
     * @param string $login    The login to look for (e.g. `dependabot[bot]`).
     *
     * @return bool True when the login reacted, and also when the reactions can't be listed, so that a
     *              request is never posted again on a guess.
     */
    private function hasReactionFrom(array $metadata, object $comment, string $login): bool
    {
        if (isset($comment->id) === false) {
            return true;
        }

        $url = "repos/{$metadata["owner"]}/{$metadata["repo"]}/issues/comments/{$comment->id}/reactions?per_page=100";
        $response = doRequestGitHub($metadata["token"], $url, null, "GET");
        if ($response->getStatusCode() !== 200) {
            return true;
        }

        $reactions = json_decode($response->getBody());
        if (is_array($reactions) === false) {
            return true;
        }

        foreach ($reactions as $reaction) {
            if (strcasecmp(($reaction->user->login ?? ""), $login) === 0) {
                return true;
            }
        }

        return false;

    }//end hasReactionFrom()


    /**
     * Posts the request with the owner's token, so that the command comes
     * from the owner and not from the bot.
     *
     * @param array  $metadata Metadata for the GitHub API request. Must include `commentsUrl` and `userToken`.
     * @param string $headSha  The pull request's current head commit.
     * @param string $sender   The pull request's author: `dependabot[bot]` or `depfu[bot]`.
     *
     * @return void
     */
    private function post(array $metadata, string $headSha, string $sender): void
    {
        $mention = "@depfu";
        if ($sender === "dependabot[bot]") {
            $mention = "@dependabot";
        }

        $marker = $this->analyzer->marker($headSha);
        $comment = ["body" => "{$marker}\n{$mention} recreate"];
        doRequestGitHub($metadata["userToken"], $metadata["commentsUrl"], $comment, "POST");

    }//end post()


}//end class
