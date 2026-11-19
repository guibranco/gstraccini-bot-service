<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\RepositoryAccessChecker;
use GuiBranco\Pancake\Response;
use PHPUnit\Framework\TestCase;

/**
 * Access checker whose GitHub answers are set by the test.
 */
class FakeRepositoryAccessChecker extends RepositoryAccessChecker
{
    /**
     * The status of an endpoint with no configured answer.
     */
    public const NOT_FOUND = 404;

    /**
     * The GitHub answers, keyed by endpoint; any other endpoint answers 404.
     *
     * @var array<string, Response>
     */
    public array $responses = [];

    /**
     * The endpoints requested, in order.
     *
     * @var string[]
     */
    public array $requested = [];


    /**
     * Answers with the configured response.
     *
     * @param string $url The endpoint.
     *
     * @return Response The configured response, or a 404.
     */
    protected function get(string $url): Response
    {
        $this->requested[] = $url;
        $response = ($this->responses[$url] ?? new Response(false, null, "Not Found", self::NOT_FOUND, $url, null));
        return $response;

    }//end get()


}//end class

class RepositoryAccessCheckerTest extends TestCase
{
    /**
     * The sender of the command.
     */
    private const LOGIN = "octocat";

    /**
     * The checker under test.
     *
     * @var FakeRepositoryAccessChecker
     */
    private FakeRepositoryAccessChecker $checker;


    /**
     * Builds the checker with no GitHub answer configured.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->checker = new FakeRepositoryAccessChecker("ghs_token");

    }//end setUp()


    /**
     * Configures GitHub's answer to `GET repos/{repository}`.
     *
     * @param string $repository The repository.
     * @param bool   $private    Whether it is private.
     *
     * @return void
     */
    private function givenRepository(string $repository, bool $private): void
    {
        $body = json_encode(["private" => $private]);
        $url = "repos/{$repository}";
        $this->checker->responses[$url] = new Response(true, $body, "", 200, $url, []);

    }//end givenRepository()


    /**
     * Configures the sender as a collaborator of the repository.
     *
     * @param string $repository The repository.
     *
     * @return void
     */
    private function givenCollaborator(string $repository): void
    {
        $url = sprintf("repos/%s/collaborators/%s", $repository, self::LOGIN);
        $this->checker->responses[$url] = new Response(true, "", "", 204, $url, []);

    }//end givenCollaborator()


    /**
     * A collaborator is recognised; anyone else is not.
     *
     * @return void
     */
    public function testCollaboratorIsRecognised(): void
    {
        $this->givenCollaborator("owner/target");

        $this->assertTrue($this->checker->isCollaborator("owner/target", self::LOGIN));
        $this->assertFalse($this->checker->isCollaborator("owner/other", self::LOGIN));

    }//end testCollaboratorIsRecognised()


    /**
     * Public labels can be read by anyone, private ones only by collaborators.
     *
     * @return void
     */
    public function testPrivateLabelsNeedCollaborator(): void
    {
        $this->givenRepository("owner/public", false);
        $this->givenRepository("owner/private", true);

        $this->assertTrue($this->checker->canReadLabels("owner/public", self::LOGIN));
        $this->assertFalse($this->checker->canReadLabels("owner/private", self::LOGIN));

        $this->givenCollaborator("owner/private");
        $this->assertTrue($this->checker->canReadLabels("owner/private", self::LOGIN));

    }//end testPrivateLabelsNeedCollaborator()


    /**
     * An unreachable repository is neither public nor readable.
     *
     * @return void
     */
    public function testUnreachableRepositoryIsDenied(): void
    {
        $this->assertFalse($this->checker->isPublic("owner/missing"));
        $this->assertFalse($this->checker->canReadLabels("owner/missing", self::LOGIN));

    }//end testUnreachableRepositoryIsDenied()


    /**
     * Malformed or traversing repositories are denied without asking GitHub.
     *
     * @return void
     */
    public function testMalformedRepositoryIsDenied(): void
    {
        $this->assertFalse($this->checker->isCollaborator("../owner", self::LOGIN));
        $this->assertFalse($this->checker->isCollaborator("owner/..", self::LOGIN));
        $this->assertFalse($this->checker->isPublic("owner/repo/issues"));
        $this->assertFalse($this->checker->canReadLabels("owner", self::LOGIN));
        $this->assertSame([], $this->checker->requested);

    }//end testMalformedRepositoryIsDenied()


}//end class
