<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\BreakingChangeDetector;
use PHPUnit\Framework\TestCase;

/**
 * How BreakingChangeDetector reads the breaking change group of a pull request description.
 */
class BreakingChangeDetectorTest extends TestCase
{


    /**
     * A description following the pull request template, with the breaking change boxes given.
     *
     * @param string $yesMark The mark of the "Yes" box.
     * @param string $noMark  The mark of the "No" box.
     *
     * @return string
     */
    private function description(string $yesMark, string $noMark): string
    {
        return <<<MARKDOWN
            Closes #1

            ## 📑 Description
            <!-- Add a brief description of the pr -->
            Something.

            ## ✅ Checks
            <!-- Make sure your pr passes the CI checks -->
            - [x] My pull request adheres to the code style of this project
            - [ ] All the tests have passed

            ## ☢️ Does this introduce a breaking change?
            <!-- If this introduces a breaking change, make sure to note it here -->
            - [{$yesMark}] Yes
            - [{$noMark}] No

            ## ℹ Additional Information

            MARKDOWN;

    }//end description()


    /**
     * "Yes" ticked is a breaking change, whatever the case of the mark.
     *
     * @return void
     */
    public function testYesTickedIsABreakingChange(): void
    {
        $detector = new BreakingChangeDetector();

        $this->assertTrue($detector->detect($this->description("x", " ")));
        $this->assertTrue($detector->detect($this->description("X", " ")));

    }//end testYesTickedIsABreakingChange()


    /**
     * "No" ticked is not a breaking change.
     *
     * @return void
     */
    public function testNoTickedIsNotABreakingChange(): void
    {
        $this->assertFalse((new BreakingChangeDetector())->detect($this->description(" ", "x")));

    }//end testNoTickedIsNotABreakingChange()


    /**
     * Neither or both ticked is unclear.
     *
     * @return void
     */
    public function testUnclearAnswerIsNull(): void
    {
        $detector = new BreakingChangeDetector();

        $this->assertNull($detector->detect($this->description(" ", " ")));
        $this->assertNull($detector->detect($this->description("x", "x")));

    }//end testUnclearAnswerIsNull()


    /**
     * A description written with Windows line endings is read too.
     *
     * @return void
     */
    public function testWindowsLineEndingsAreRead(): void
    {
        $body = str_replace("\n", "\r\n", $this->description("x", " "));

        $this->assertTrue((new BreakingChangeDetector())->detect($body));

    }//end testWindowsLineEndingsAreRead()


    /**
     * Yes/No boxes under another heading don't count.
     *
     * @return void
     */
    public function testDescriptionWithoutTheGroupIsUnclear(): void
    {
        $this->assertNull((new BreakingChangeDetector())->detect("## ✅ Checks\n- [x] Yes\n- [ ] No\n"));

    }//end testDescriptionWithoutTheGroupIsUnclear()


    /**
     * An empty description is unclear.
     *
     * @return void
     */
    public function testEmptyDescriptionIsUnclear(): void
    {
        $detector = new BreakingChangeDetector();

        $this->assertNull($detector->detect(null));
        $this->assertNull($detector->detect(""));

    }//end testEmptyDescriptionIsUnclear()


}//end class
