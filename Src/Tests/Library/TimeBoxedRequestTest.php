<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use PHPUnit\Framework\TestCase;

/**
 * TimeBoxedRequest sends GET and POST through Pancake's batch path, the one
 * that has a total timeout, as a batch of one.
 */
class TimeBoxedRequestTest extends TestCase
{
    /** The base URL of the log server. */
    private const BASE_URL = "https://logs.example.com";


    /**
     * A POST goes through the batch with its headers and body.
     *
     * @return void
     */
    public function testPostGoesThroughTheBatch(): void
    {
        $request = new RecordingTimeBoxedRequest(self::BASE_URL);

        $response = $request->post("api/v1/logs", ["X-Api-Key: key"], "{}");

        $this->assertSame(["request", "api/v1/logs", ["X-Api-Key: key"], "POST", "{}"], $request->added[0]);
        $this->assertSame(1, $request->batches);
        $this->assertSame(RecordingTimeBoxedRequest::BODY, $response->getBody());

    }//end testPostGoesThroughTheBatch()


    /**
     * A GET goes through the batch with its headers and no body.
     *
     * @return void
     */
    public function testGetGoesThroughTheBatch(): void
    {
        $request = new RecordingTimeBoxedRequest(self::BASE_URL);

        $request->get("health", ["Accept: application/json"]);

        $this->assertSame(["request", "health", ["Accept: application/json"], "GET", null], $request->added[0]);
        $this->assertSame(1, $request->batches);

    }//end testGetGoesThroughTheBatch()


    /**
     * Each call is a batch of its own.
     *
     * @return void
     */
    public function testEachCallIsABatchOfItsOwn(): void
    {
        $request = new RecordingTimeBoxedRequest(self::BASE_URL);

        $request->post("api/v1/logs", [], "{}");
        $request->post("api/v1/logs", [], "{}");

        $this->assertSame(2, $request->batches);

    }//end testEachCallIsABatchOfItsOwn()


}//end class
