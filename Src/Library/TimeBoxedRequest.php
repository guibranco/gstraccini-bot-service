<?php

namespace GuiBranco\GStracciniBot\Library;

use GuiBranco\Pancake\Request;
use GuiBranco\Pancake\Response;

/**
 * A Request whose GET and POST calls give up after 30 seconds. Pancake's Request
 * sends them with no total timeout, so a server that accepts the connection and
 * never answers blocks the caller for good; its batch path does set one, so each
 * call goes through a batch of one. Injected into LogStream so a stalled log
 * server cannot hold up the processing of a pull request.
 */
class TimeBoxedRequest extends Request
{
    /** The key of the single request in each batch. */
    private const REQUEST_KEY = "request";


    /**
     * Sends a GET request, with the timeout of a batch.
     *
     * @param string $url          The URL, absolute or relative to the base URL.
     * @param mixed  ...$arguments The headers, as Request::get() takes them.
     *
     * @return Response The response, or an error response when the request failed or timed out.
     */
    public function get($url, ...$arguments): Response
    {
        return $this->send($url, ($arguments[0] ?? []), "GET", null);

    }//end get()


    /**
     * Sends a POST request, with the timeout of a batch.
     *
     * @param string $url          The URL, absolute or relative to the base URL.
     * @param mixed  ...$arguments The headers and the body, as Request::post() takes them.
     *
     * @return Response The response, or an error response when the request failed or timed out.
     */
    public function post($url, ...$arguments): Response
    {
        return $this->send($url, ($arguments[0] ?? []), "POST", ($arguments[1] ?? null));

    }//end post()


    /**
     * Sends a request as a batch of one.
     *
     * @param string $url     The URL, absolute or relative to the base URL.
     * @param array  $headers The headers.
     * @param string $method  The HTTP method.
     * @param mixed  $payload The body, if any.
     *
     * @return Response The response.
     */
    private function send(string $url, array $headers, string $method, mixed $payload): Response
    {
        $this->addRequest(self::REQUEST_KEY, $url, $headers, $method, $payload);

        return $this->executeBatch()[self::REQUEST_KEY];

    }//end send()


}//end class
