<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\InfisicalIgnoreFileUpdater;
use PHPUnit\Framework\TestCase;

class InfisicalIgnoreFileUpdaterTest extends TestCase
{
    private InfisicalIgnoreFileUpdater $updater;

    protected function setUp(): void
    {
        $this->updater = new InfisicalIgnoreFileUpdater();
    }

    public function testCreatesFileWhenNoExistingContent(): void
    {
        $result = $this->updater->merge(null, ["fingerprint-a", "fingerprint-b"]);

        $this->assertSame("fingerprint-a\nfingerprint-b\n", $result);
    }

    public function testAppendsNewEntriesToExistingContent(): void
    {
        $existing = "existing-a\nexisting-b\n";

        $result = $this->updater->merge($existing, ["new-c"]);

        $this->assertSame("existing-a\nexisting-b\nnew-c\n", $result);
    }

    public function testDoesNotDuplicateEntriesAlreadyPresent(): void
    {
        $existing = "existing-a\nexisting-b\n";

        $result = $this->updater->merge($existing, ["existing-b", "new-c"]);

        $this->assertSame("existing-a\nexisting-b\nnew-c\n", $result);
    }

    public function testDedupesWithinNewLinesThemselves(): void
    {
        $result = $this->updater->merge(null, ["new-a", "new-a", "new-b"]);

        $this->assertSame("new-a\nnew-b\n", $result);
    }

    public function testPreservesExistingLineOrder(): void
    {
        $existing = "z-line\na-line\n";

        $result = $this->updater->merge($existing, ["new-line"]);

        $this->assertSame("z-line\na-line\nnew-line\n", $result);
    }
}
