<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\HelpCommentBuilder;
use PHPUnit\Framework\TestCase;

class HelpCommentBuilderTest extends TestCase
{
    private const COMMANDS = <<<'JSON'
        [
            {"command": "help", "description": "Shows the help message."},
            {"command": "update snapshot", "description": "Updates test snapshots."},
            {"command": "fix csproj", "description": "Fixes csproj.", "dev": true},
            {
                "command": "bump version",
                "description": "Bumps the version.",
                "parameters": [
                    {"parameter": "type", "required": true, "description": "major, minor or patch."}
                ]
            }
        ]
        JSON;

    /**
     * The builder under test.
     *
     * @var HelpCommentBuilder
     */
    private HelpCommentBuilder $builder;


    /**
     * Creates the builder.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->builder = new HelpCommentBuilder();

    }//end setUp()


    /**
     * Builds the help comment of the test commands.
     *
     * @return string
     */
    private function helpComment(): string
    {
        return $this->builder->build("gstraccini", json_decode(self::COMMANDS));

    }//end helpComment()


    /**
     * Only parameterless commands get a checkbox.
     *
     * @return void
     */
    public function testBuildAddsMarkerAndCheckboxes(): void
    {
        $body = $this->helpComment();

        $this->assertStringContainsString(HelpCommentBuilder::MARKER, $body);
        $this->assertStringContainsString("- [ ] `@gstraccini help` - ", $body);
        $this->assertStringContainsString("- [ ] `@gstraccini update snapshot` - ", $body);
        $this->assertStringContainsString("- `@gstraccini bump version <type>` - ", $body);
        $this->assertStringContainsString("\t- `type` - `[required]` major, minor or patch.", $body);
        $this->assertStringContainsString("In development", $body);

    }//end testBuildAddsMarkerAndCheckboxes()


    /**
     * Nothing is ticked in a fresh help comment.
     *
     * @return void
     */
    public function testFindTickedCommandWhenNoneTicked(): void
    {
        $this->assertNull($this->builder->findTickedCommand($this->helpComment(), "gstraccini"));

    }//end testFindTickedCommandWhenNoneTicked()


    /**
     * The ticked command is found.
     *
     * @return void
     */
    public function testFindTickedCommand(): void
    {
        $body = str_replace("- [ ] `@gstraccini update snapshot`", "- [x] `@gstraccini update snapshot`", $this->helpComment());

        $this->assertSame("update snapshot", $this->builder->findTickedCommand($body, "gstraccini"));

    }//end testFindTickedCommand()


    /**
     * Every checkbox is removed and the triggered command is marked.
     *
     * @return void
     */
    public function testDisableCheckboxes(): void
    {
        $body     = str_replace("- [ ] `@gstraccini update snapshot`", "- [x] `@gstraccini update snapshot`", $this->helpComment());
        $disabled = $this->builder->disableCheckboxes($body, "gstraccini", "update snapshot", "octocat");

        $this->assertDoesNotMatchRegularExpression("/^-\s\[[ xX]\]\s/m", $disabled);
        $this->assertStringContainsString("- ✅ `@gstraccini update snapshot` - ", $disabled);
        $this->assertStringContainsString("- `@gstraccini help` - ", $disabled);
        $this->assertStringContainsString("- `@gstraccini fix csproj` - ", $disabled);
        $this->assertStringContainsString(HelpCommentBuilder::COMPLETION_MARKER, $disabled);
        $this->assertStringContainsString("triggered by @octocat", $disabled);
        $this->assertNull($this->builder->findTickedCommand($disabled, "gstraccini"));

    }//end testDisableCheckboxes()


    /**
     * Every tick is cleared.
     *
     * @return void
     */
    public function testUntickCheckboxes(): void
    {
        $body   = $this->helpComment();
        $ticked = str_replace("- [ ] `@gstraccini help`", "- [X] `@gstraccini help`", $body);

        $this->assertSame($body, $this->builder->untickCheckboxes($ticked));

    }//end testUntickCheckboxes()


}//end class
