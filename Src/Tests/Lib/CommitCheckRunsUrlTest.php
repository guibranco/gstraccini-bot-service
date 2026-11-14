<?php

namespace GuiBranco\GStracciniBot\Tests\Lib;

use PHPUnit\Framework\TestCase;

require_once sprintf("%s/../../lib/github.php", __DIR__);

/**
 * How commitCheckRunsUrl() builds the lookup used to reuse the bot's existing check run
 * for a commit instead of creating a new one on every run.
 */
class CommitCheckRunsUrlTest extends TestCase
{


    /**
     * The commit endpoint is derived from the repository's check-runs endpoint and filtered
     * to the latest check run with the (encoded) name.
     *
     * @return void
     */
    public function testBuildsCommitLookupUrl(): void
    {
        $metadata = ["checkRunUrl" => "repos/guibranco/gstraccini-bot-service/check-runs"];

        $url = commitCheckRunsUrl($metadata, "abc123", "GStraccini Checks: Pull Request");

        $this->assertSame(
            "repos/guibranco/gstraccini-bot-service/commits/abc123/check-runs?check_name=GStraccini%20Checks%3A%20Pull%20Request&filter=latest",
            $url
        );

    }//end testBuildsCommitLookupUrl()


}//end class
