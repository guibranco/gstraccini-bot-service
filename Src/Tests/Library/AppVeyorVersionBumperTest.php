<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\AppVeyorVersionBumper;
use PHPUnit\Framework\TestCase;

/**
 * How AppVeyorVersionBumper reads and bumps the version of an appveyor.yml file.
 */
class AppVeyorVersionBumperTest extends TestCase
{
    private const YAML = "version: 1.4.{build}\nskip_tags: true\nenvironment:\n  version: 9.9.9\n";


    /**
     * Only the top-level version is read.
     *
     * @return void
     */
    public function testReadsTheTopLevelVersion(): void
    {
        $bumper = new AppVeyorVersionBumper();

        $this->assertSame("1.4.{build}", $bumper->readVersion(self::YAML));
        $this->assertNull($bumper->readVersion("environment:\n  version: 2.0.0\n"));

    }//end testReadsTheTopLevelVersion()


    /**
     * The major is the leading number.
     *
     * @return void
     */
    public function testReadsTheMajor(): void
    {
        $bumper = new AppVeyorVersionBumper();

        $this->assertSame(1, $bumper->majorOf("1.4.{build}"));
        $this->assertSame(12, $bumper->majorOf("12.{build}"));
        $this->assertNull($bumper->majorOf("{build}"));

    }//end testReadsTheMajor()


    /**
     * The next major resets the numeric parts and keeps the placeholders.
     *
     * @return void
     */
    public function testNextMajorResetsTheNumericParts(): void
    {
        $bumper = new AppVeyorVersionBumper();

        $this->assertSame("2.0.{build}", $bumper->nextMajor("1.4.{build}"));
        $this->assertSame("4.0.0.{build}", $bumper->nextMajor("3.2.1.{build}"));
        $this->assertSame("2.{build}", $bumper->nextMajor("1.{build}"));
        $this->assertSame("2.0.{build}-{branch}", $bumper->nextMajor("1.4.{build}-{branch}"));
        $this->assertNull($bumper->nextMajor("{build}"));

    }//end testNextMajorResetsTheNumericParts()


    /**
     * Only the top-level version line changes.
     *
     * @return void
     */
    public function testBumpsOnlyTheTopLevelVersionLine(): void
    {
        $result = (new AppVeyorVersionBumper())->bumpMajor(self::YAML);

        $this->assertSame("1.4.{build}", $result["from"]);
        $this->assertSame("2.0.{build}", $result["to"]);
        $this->assertSame("version: 2.0.{build}\nskip_tags: true\nenvironment:\n  version: 9.9.9\n", $result["content"]);

    }//end testBumpsOnlyTheTopLevelVersionLine()


    /**
     * Quotes, comments and Windows line endings are kept.
     *
     * @return void
     */
    public function testKeepsQuotesCommentsAndLineEndings(): void
    {
        $result = (new AppVeyorVersionBumper())->bumpMajor("version: '1.4.{build}' # the version\r\nskip_tags: true\r\n");

        $this->assertSame("version: '2.0.{build}' # the version\r\nskip_tags: true\r\n", $result["content"]);

    }//end testKeepsQuotesCommentsAndLineEndings()


    /**
     * Nothing is bumped without a numeric major.
     *
     * @return void
     */
    public function testNothingToBumpWithoutANumericMajor(): void
    {
        $bumper = new AppVeyorVersionBumper();

        $this->assertNull($bumper->bumpMajor("version: '{build}'\n"));
        $this->assertNull($bumper->bumpMajor("image: Ubuntu\n"));

    }//end testNothingToBumpWithoutANumericMajor()


}//end class
