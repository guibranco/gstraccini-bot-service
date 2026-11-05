<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use DateTimeImmutable;
use stdClass;

/**
 * The comments of a pull request, as GitHub's REST API lists them, for the
 * tests of the recreate requests. The request they are built around is the
 * one of guibranco/grimoire-ui#350, which Dependabot never acknowledged.
 */
class RecreateRequestFixtures
{
    /** The head commit of the pull request. */
    public const HEAD_SHA = "3e235d6b741b467bd5b06da1615c41b076cb1c0c";

    /** A previous head commit of the pull request. */
    public const OTHER_SHA = "5ee47505d89d4f2843232dc07c981b53d67926c8";

    /** When the request was posted. */
    public const REQUESTED_AT = "2026-10-21T17:40:38Z";

    /** The comment the request was posted as. */
    public const REQUEST_ID = 6322533681;

    /** Two weeks later, when the request was found still unanswered. */
    public const TWO_WEEKS_LATER = "2026-11-05T16:58:00Z";


    /**
     * A recreate request for the head commit.
     *
     * @param string $createdAt When it was posted.
     * @param int    $reactions How many reactions its rollup counts.
     *
     * @return object
     */
    public function request(string $createdAt, int $reactions): object
    {
        return $this->requestFor(self::HEAD_SHA, $createdAt, $reactions);

    }//end request()


    /**
     * A recreate request for a given head commit.
     *
     * @param string $headSha   The head commit it was posted for.
     * @param string $createdAt When it was posted.
     * @param int    $reactions How many reactions its rollup counts.
     *
     * @return object
     */
    public function requestFor(string $headSha, string $createdAt, int $reactions): object
    {
        $request = $this->comment("<!--GStraccini:{$headSha}-->\n@dependabot recreate", $createdAt);
        $request->id = self::REQUEST_ID;
        $request->user->login = "guibranco";
        $request->reactions->total_count = $reactions;

        return $request;

    }//end requestFor()


    /**
     * As many requests for the head commit as asked for, a day apart from
     * the first one on, none of them with a reaction.
     *
     * @param int $count How many requests.
     *
     * @return array
     */
    public function unanswered(int $count): array
    {
        $requests = [];
        for ($day = 21; $day < (21 + $count); $day++) {
            $requests[] = $this->request(sprintf("2026-10-%dT17:40:38Z", $day), 0);
        }

        return $requests;

    }//end unanswered()


    /**
     * Any other comment, without reactions.
     *
     * @param string $body      What it says.
     * @param string $createdAt When it was posted.
     *
     * @return object
     */
    public function comment(string $body, string $createdAt): object
    {
        $comment = new stdClass();
        $comment->id = 1;
        $comment->body = $body;
        $comment->created_at = $createdAt;
        $comment->user = new stdClass();
        $comment->user->login = "coderabbitai[bot]";
        $comment->reactions = new stdClass();
        $comment->reactions->total_count = 0;

        return $comment;

    }//end comment()


    /**
     * A reaction, as GitHub lists the reactions to a comment.
     *
     * @param string $login Who reacted.
     *
     * @return object
     */
    public function reaction(string $login): object
    {
        $reaction = new stdClass();
        $reaction->content = "+1";
        $reaction->user = new stdClass();
        $reaction->user->login = $login;

        return $reaction;

    }//end reaction()


    /**
     * A moment counted from when the request was posted.
     *
     * @param int $seconds How long after the request.
     *
     * @return DateTimeImmutable
     */
    public function after(int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::REQUESTED_AT))->modify("+{$seconds} seconds");

    }//end after()


}//end class
