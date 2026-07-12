<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\InfisicalIgnoreSuggestionParser;
use PHPUnit\Framework\TestCase;

class InfisicalIgnoreSuggestionParserTest extends TestCase
{
    private InfisicalIgnoreSuggestionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InfisicalIgnoreSuggestionParser();
    }

    public function testParsesSingleLineSuggestion(): void
    {
        $body = "### Suggested update for `.infisicalignore`\n\n" .
            "```suggestion:.infisicalignore\n" .
            "616cdf6227a38caf6e9a1f2dc1f4ce33fc11d124:tests/endpoint-tests.sh:curl-auth-header:82\n" .
            "```\n";

        $result = $this->parser->parse($body);

        $this->assertSame(
            ["616cdf6227a38caf6e9a1f2dc1f4ce33fc11d124:tests/endpoint-tests.sh:curl-auth-header:82"],
            $result
        );
    }

    public function testParsesMultipleFingerprintLines(): void
    {
        $body = "```suggestion:.infisicalignore\n" .
            "616cdf6227a38caf6e9a1f2dc1f4ce33fc11d124:tests/endpoint-tests.sh:curl-auth-header:82\n" .
            "9098ba555a5fd5a04b2247f07f4e7d0812968684:public/openapi.yaml:generic-api-key:1439\n" .
            "9098ba555a5fd5a04b2247f07f4e7d0812968684:public/openapi.yaml:generic-api-key:1618\n" .
            "```\n";

        $result = $this->parser->parse($body);

        $this->assertCount(3, $result);
        $this->assertSame("9098ba555a5fd5a04b2247f07f4e7d0812968684:public/openapi.yaml:generic-api-key:1618", $result[2]);
    }

    public function testReturnsNullWhenNoSuggestionFencePresent(): void
    {
        $result = $this->parser->parse("Just a regular comment with no suggestion block.");

        $this->assertNull($result);
    }

    public function testReturnsNullWhenSuggestionBlockIsEmpty(): void
    {
        $body = "```suggestion:.infisicalignore\n\n```\n";

        $result = $this->parser->parse($body);

        $this->assertNull($result);
    }

    public function testIgnoresSurroundingMarkdown(): void
    {
        $body = "### Suggested update for `.infisicalignore`\n\n" .
            "Apply this suggestion to ignore detected fingerprints:\n\n" .
            "```suggestion:.infisicalignore\n" .
            "616cdf6227a38caf6e9a1f2dc1f4ce33fc11d124:tests/endpoint-tests.sh:curl-auth-header:82\n" .
            "```\n\n" .
            "Some trailing note.\n";

        $result = $this->parser->parse($body);

        $this->assertSame(
            ["616cdf6227a38caf6e9a1f2dc1f4ce33fc11d124:tests/endpoint-tests.sh:curl-auth-header:82"],
            $result
        );
    }
}
