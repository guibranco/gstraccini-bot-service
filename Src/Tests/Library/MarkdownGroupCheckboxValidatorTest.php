<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\MarkdownGroupCheckboxValidator;
use PHPUnit\Framework\TestCase;

class MarkdownGroupCheckboxValidatorTest extends TestCase
{
    private MarkdownGroupCheckboxValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MarkdownGroupCheckboxValidator();
    }

    public function testValidatesCheckedGroupWithNoErrors(): void
    {
        $body = "## Does this include tests?\n- [x] Yes\n- [ ] No\n";

        $result = $this->validator->validateCheckboxes($body);

        $this->assertSame(1, $result["found"]);
        $this->assertSame([], $result["errors"]);
        $this->assertSame(["Yes"], $result["groups"][0]["checked"]);
        $this->assertSame(["No"], $result["groups"][0]["unchecked"]);
    }

    public function testReportsErrorWhenNoOptionSelected(): void
    {
        $body = "## Does this include tests?\n- [ ] Yes\n- [ ] No\n";

        $result = $this->validator->validateCheckboxes($body);

        $this->assertNotEmpty($result["errors"]);
        $this->assertStringContainsString("No checkbox selected", implode("\n", $result["errors"]));
    }

    public function testReportsErrorWhenBothYesAndNoSelected(): void
    {
        $body = "## Does this include tests?\n- [x] Yes\n- [x] No\n";

        $result = $this->validator->validateCheckboxes($body);

        $this->assertNotEmpty($result["errors"]);
        $this->assertStringContainsString("select exactly one option", $result["errors"][0]);
    }

    public function testReturnsNotFoundWhenNoGroupsPresent(): void
    {
        $result = $this->validator->validateCheckboxes("Just a plain description with no groups.");

        $this->assertSame(0, $result["found"]);
        $this->assertSame([], $result["errors"]);
    }

    public function testGenerateReportReturnsErrorsWhenPresent(): void
    {
        $report = $this->validator->generateReport([
            "errors" => ["No checkbox selected in group: Example"],
        ]);

        $this->assertSame("No checkbox selected in group: Example", $report);
    }

    public function testGenerateReportListsCheckedAndUncheckedItems(): void
    {
        $report = $this->validator->generateReport([
            "errors" => [],
            "groups" => [
                [
                    "group" => "Does this include tests?",
                    "checked" => ["Yes"],
                    "unchecked" => ["No"],
                ],
            ],
        ]);

        $this->assertStringContainsString("Does this include tests?", $report);
        $this->assertStringContainsString("Checked items:", $report);
        $this->assertStringContainsString("- Yes", $report);
        $this->assertStringContainsString("Unchecked items:", $report);
        $this->assertStringContainsString("- No", $report);
    }
}
