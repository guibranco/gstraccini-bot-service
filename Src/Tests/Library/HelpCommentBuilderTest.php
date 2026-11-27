<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\HelpCommentBuilder;
use PHPUnit\Framework\TestCase;

class HelpCommentBuilderTest extends TestCase
{
    private HelpCommentBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HelpCommentBuilder();
    }

    private function commands(): array
    {
        return json_decode(json_encode([
            ["command" => "help", "description" => "Shows the help message."],
            ["command" => "update snapshot", "description" => "Updates test snapshots."],
            ["command" => "fix csproj", "description" => "Fixes csproj.", "dev" => true],
            [
                "command" => "bump version",
                "description" => "Bumps the version.",
                "parameters" => [
                    ["parameter" => "type", "required" => true, "description" => "major, minor or patch."],
                ],
            ],
        ]));
    }

    public function testBuildAddsMarkerAndCheckboxes(): void
    {
        $body = $this->builder->build("gstraccini", $this->commands());

        $this->assertStringContainsString(HelpCommentBuilder::MARKER, $body);
        $this->assertStringContainsString("- [ ] `@gstraccini help` - ", $body);
        $this->assertStringContainsString("- [ ] `@gstraccini update snapshot` - ", $body);
        $this->assertStringContainsString("- `@gstraccini bump version <type>` - ", $body);
        $this->assertStringContainsString("In development", $body);
    }

    public function testFindTickedCommandWhenNoneTicked(): void
    {
        $body = $this->builder->build("gstraccini", $this->commands());

        $this->assertNull($this->builder->findTickedCommand($body, "gstraccini"));
    }

    public function testFindTickedCommand(): void
    {
        $body = $this->builder->build("gstraccini", $this->commands());
        $body = str_replace("- [ ] `@gstraccini update snapshot`", "- [x] `@gstraccini update snapshot`", $body);

        $this->assertSame("update snapshot", $this->builder->findTickedCommand($body, "gstraccini"));
    }

    public function testDisableCheckboxes(): void
    {
        $body = $this->builder->build("gstraccini", $this->commands());
        $body = str_replace("- [ ] `@gstraccini update snapshot`", "- [x] `@gstraccini update snapshot`", $body);

        $disabled = $this->builder->disableCheckboxes($body, "gstraccini", "update snapshot", "octocat");

        $this->assertDoesNotMatchRegularExpression("/^-\s\[[ xX]\]\s/m", $disabled);
        $this->assertStringContainsString("- ✅ `@gstraccini update snapshot` - ", $disabled);
        $this->assertStringContainsString("- `@gstraccini help` - ", $disabled);
        $this->assertStringContainsString("- `@gstraccini fix csproj` - ", $disabled);
        $this->assertStringContainsString(HelpCommentBuilder::COMPLETION_MARKER, $disabled);
        $this->assertStringContainsString("triggered by @octocat", $disabled);
        $this->assertNull($this->builder->findTickedCommand($disabled, "gstraccini"));
    }

    public function testUntickCheckboxes(): void
    {
        $body = $this->builder->build("gstraccini", $this->commands());
        $ticked = str_replace("- [ ] `@gstraccini help`", "- [X] `@gstraccini help`", $body);

        $this->assertSame($body, $this->builder->untickCheckboxes($ticked));
    }
}
