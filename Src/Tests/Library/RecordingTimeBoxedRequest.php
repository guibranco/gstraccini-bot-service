<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\TimeBoxedRequest;
use GuiBranco\Pancake\Response;

/**
 * A TimeBoxedRequest that keeps what it is asked to send instead of sending
 * it, and answers each batch with a response for the last request added.
 */
class RecordingTimeBoxedRequest extends TimeBoxedRequest
{
    /** The body of every response. */
    public const BODY = "ok";

    /**
     * The requests added, in order, each as its key, URL, headers, method and body.
     *
     * @var array
     */
    public array $added = [];

    /**
     * How many times the batch was run.
     *
     * @var integer
     */
    public int $batches = 0;


    /**
     * Keeps a request instead of queueing it.
     *
     * @param string $key          The key of the request.
     * @param string $url          The URL.
     * @param mixed  ...$arguments The headers, the method and the body.
     *
     * @return void
     */
    public function addRequest(string $key, string $url, ...$arguments): void
    {
        $this->added[] = array_merge([$key], [$url], $arguments);

    }//end addRequest()


    /**
     * Answers the last request added.
     *
     * @return array The response, by key.
     */
    public function executeBatch(): array
    {
        $this->batches++;
        $last = end($this->added);

        return [$last[0] => new Response(true, self::BODY, $last[3], 200, $last[1], [])];

    }//end executeBatch()


}//end class
