<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\RecreateRequestStatus;
use PHPUnit\Framework\TestCase;

/**
 * What each status of a recreate request means for the bot: whether the
 * request is posted, and what goes to the processing log and to the warnings.
 */
class RecreateRequestStatusTest extends TestCase
{
    /** When the last request was posted. */
    private const REQUESTED_AT = RecreateRequestFixtures::REQUESTED_AT;


    /**
     * The first request is posted, and the line that announces it is already
     * in the log.
     *
     * @return void
     */
    public function testAFirstRequestIsPostedWithNothingToAdd(): void
    {
        $status = new RecreateRequestStatus(RecreateRequestStatus::NOT_REQUESTED, 0, null);

        $this->assertTrue($status->mustRequest());
        $this->assertSame("", $status->summary());
        $this->assertSame("", $status->icon());
        $this->assertSame("", $status->warning());

    }//end testAFirstRequestIsPostedWithNothingToAdd()


    /**
     * A request the dependency bot acknowledged is reported as before.
     *
     * @return void
     */
    public function testAnAcknowledgedRequestIsLeftAlone(): void
    {
        $status = new RecreateRequestStatus(RecreateRequestStatus::ACKNOWLEDGED, 1, self::REQUESTED_AT);

        $this->assertFalse($status->mustRequest());
        $this->assertSame("Already requested to recreate", $status->summary());
        $this->assertSame("⚠️", $status->icon());
        $this->assertSame("", $status->warning());

    }//end testAnAcknowledgedRequestIsLeftAlone()


    /**
     * A request posted a moment ago is waited for.
     *
     * @return void
     */
    public function testARecentRequestIsWaitedFor(): void
    {
        $status = new RecreateRequestStatus(RecreateRequestStatus::WAITING, 1, self::REQUESTED_AT);

        $this->assertFalse($status->mustRequest());
        $this->assertSame("Already requested to recreate, not acknowledged yet", $status->summary());
        $this->assertSame("⏳", $status->icon());
        $this->assertSame("", $status->warning());

    }//end testARecentRequestIsWaitedFor()


    /**
     * A request that was never acknowledged is posted again, which is worth
     * a warning, and the log says which attempt it is.
     *
     * @return void
     */
    public function testAnUnacknowledgedRequestIsPostedAgainWithAWarning(): void
    {
        $status = new RecreateRequestStatus(RecreateRequestStatus::UNACKNOWLEDGED, 1, self::REQUESTED_AT);

        $this->assertTrue($status->mustRequest());
        $this->assertSame("Recreate request from 2026-10-21T17:40:38Z was never acknowledged, requesting again (2/3)", $status->summary());
        $this->assertSame("🔁", $status->icon());
        $this->assertSame('recreate request from {last_requested_at} was never acknowledged — requesting again', $status->warning());

    }//end testAnUnacknowledgedRequestIsPostedAgainWithAWarning()


    /**
     * Giving up leaves the pull request with its conflicts, so it is warned
     * about too.
     *
     * @return void
     */
    public function testGivingUpIsReportedWithAWarning(): void
    {
        $status = new RecreateRequestStatus(RecreateRequestStatus::GAVE_UP, 3, "2026-10-23T17:40:38Z");

        $this->assertFalse($status->mustRequest());
        $this->assertSame("Recreate requested 3 times and never acknowledged, giving up", $status->summary());
        $this->assertSame("❌", $status->icon());
        $this->assertSame('still has conflicts — {requests} recreate requests were never acknowledged, giving up', $status->warning());

    }//end testGivingUpIsReportedWithAWarning()


    /**
     * The warnings go to the log with the figures of the request.
     *
     * @return void
     */
    public function testTheContextCarriesTheFiguresOfTheRequest(): void
    {
        $status = new RecreateRequestStatus(RecreateRequestStatus::UNACKNOWLEDGED, 2, self::REQUESTED_AT);

        $this->assertSame(["requests" => 2, "last_requested_at" => "2026-10-21T17:40:38Z"], $status->context());

    }//end testTheContextCarriesTheFiguresOfTheRequest()


}//end class
