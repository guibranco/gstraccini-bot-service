<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\Pancake\Response;

/**
 * A stand-in for GitHub's REST API, for the tests of what the classes of the
 * Library namespace ask it through doRequestGitHub(): it answers a request
 * with what the test set up for its URL and keeps track of every request.
 * Creating one puts it in use.
 */
class FakeGitHub
{
    /** The token the requests that read are expected to carry. */
    public const TOKEN = "fake-token";

    /** The owner's token, the one a comment is expected to be posted with. */
    public const USER_TOKEN = "fake-user-token";

    /** The comments endpoint of the pull request the tests work on. */
    public const COMMENTS_URL = "repos/owner/repo/issues/1/comments";

    /**
     * The fake that answers doRequestGitHub(): the last one created.
     *
     * @var FakeGitHub|null
     */
    public static ?FakeGitHub $current = null;

    /**
     * The answers set up, by the part of the URL they are for.
     *
     * @var array
     */
    private array $responses = [];

    /**
     * Every request received, as `METHOD token url`.
     *
     * @var array
     */
    private array $requests = [];

    /**
     * The body of every comment posted.
     *
     * @var array
     */
    private array $posted = [];

    /**
     * The error status codes each caller said it handles itself, by URL.
     *
     * @var array
     */
    private array $expectedStatusCodes = [];


    /**
     * Puts this fake in use.
     */
    public function __construct()
    {
        self::$current = $this;

    }//end __construct()


    /**
     * The metadata a handler passes along for the pull request.
     *
     * @return array
     */
    public function metadata(): array
    {
        $metadata = ["token" => self::TOKEN];
        $metadata["userToken"] = self::USER_TOKEN;
        $metadata["commentsUrl"] = self::COMMENTS_URL;
        $metadata["owner"] = "owner";
        $metadata["repo"] = "repo";

        return $metadata;

    }//end metadata()


    /**
     * Sets up a page of the pull request's comments.
     *
     * @param int   $page     The page, starting at 1.
     * @param array $comments The comments listed on it.
     *
     * @return void
     */
    public function comments(int $page, array $comments): void
    {
        $this->answer(sprintf("%s?per_page=100&page=%d", self::COMMENTS_URL, $page), json_encode($comments));

    }//end comments()


    /**
     * Sets up who reacted to a comment.
     *
     * @param int   $commentId The comment.
     * @param array $reactions Its reactions, as GitHub lists them.
     *
     * @return void
     */
    public function reactions(int $commentId, array $reactions): void
    {
        $this->answer("issues/comments/{$commentId}/reactions", json_encode($reactions));

    }//end reactions()


    /**
     * Sets up the body GitHub answers with, with a 200, to the requests whose
     * URL has a given part.
     *
     * @param string $urlPart The part of the URL.
     * @param string $body    The body of the answer.
     *
     * @return void
     */
    public function answer(string $urlPart, string $body): void
    {
        $this->responses[$urlPart] = new Response(true, $body, "", 200, $urlPart, []);

    }//end answer()


    /**
     * Makes the requests whose URL has a given part fail.
     *
     * @param string $urlPart    The part of the URL.
     * @param int    $statusCode The status GitHub answers with.
     *
     * @return void
     */
    public function fail(string $urlPart, int $statusCode): void
    {
        $this->responses[$urlPart] = new Response(false, null, "HTTP {$statusCode}", $statusCode, $urlPart, null);

    }//end fail()


    /**
     * Answers a request: a comment is accepted, and any other request gets
     * what was set up for its URL, the first match winning, or is not found
     * when nothing was.
     *
     * @param string $token               The token the request carries.
     * @param string $url                 The URL, relative to the API root.
     * @param mixed  $data                The payload, null when there is none.
     * @param string $method              The HTTP method.
     * @param int[]  $expectedStatusCodes The error status codes the caller handles itself.
     *
     * @return Response
     */
    public function handle(string $token, string $url, mixed $data, string $method, array $expectedStatusCodes = []): Response
    {
        $this->requests[] = "{$method} {$token} {$url}";
        $this->expectedStatusCodes[$url] = $expectedStatusCodes;
        if ($method === "POST") {
            $this->posted[] = $data["body"];

            return new Response(true, "{}", "", 201, $url, []);
        }

        foreach ($this->responses as $urlPart => $response) {
            if (str_contains($url, $urlPart) === true) {
                return $response;
            }
        }

        return new Response(false, null, "Nothing was set up for {$url}", 404, $url, null);

    }//end handle()


    /**
     * Every request received, in order.
     *
     * @return array Each one as `METHOD token url`.
     */
    public function requests(): array
    {
        return $this->requests;

    }//end requests()


    /**
     * The error status codes the caller said it handles itself, for a URL.
     *
     * @param string $url The URL, relative to the API root.
     *
     * @return int[] Empty when none were given or the URL wasn't requested.
     */
    public function expectedStatusCodes(string $url): array
    {
        return ($this->expectedStatusCodes[$url] ?? []);

    }//end expectedStatusCodes()


    /**
     * The comments posted, in order.
     *
     * @return array The body of each one.
     */
    public function posted(): array
    {
        return $this->posted;

    }//end posted()


}//end class

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\GStracciniBot\Tests\Library\FakeGitHub;
use GuiBranco\Pancake\Response;

/*
    Test-only stand-in for the global doRequestGitHub(), for the classes of this namespace. PHP only
    falls back to the global function when the namespace has none of that name, so this one takes the
    place of the real one, which calls GitHub, in every test that runs after this file is loaded (see
    PullRequestsHandlerTest for the same thing in the Handlers namespace).
*/


/**
 * Hands the request to the fake in use.
 *
 * @param string $token               The token the request carries.
 * @param string $url                 The URL, relative to the API root.
 * @param mixed  $data                The payload, null when there is none.
 * @param string $method              The HTTP method.
 * @param string $accept              The Accept header.
 * @param int[]  $expectedStatusCodes The error status codes the caller handles itself.
 *
 * @return Response What the fake answers.
 */
function doRequestGitHub(string $token, string $url, mixed $data, string $method, string $accept = "application/vnd.github+json", array $expectedStatusCodes = []): Response
{
    return FakeGitHub::$current->handle($token, $url, $data, $method, $expectedStatusCodes);

}//end doRequestGitHub()
