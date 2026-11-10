<?php

namespace GuiBranco\GStracciniBot\Tests\Handlers;

use GuiBranco\GStracciniBot\Handlers\CommentsHandler;
use PHPUnit\Framework\TestCase;

class CommentsHandlerTest extends TestCase
{
    private CommentsHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new CommentsHandler();
    }

    public function testBatchCopyWorkflowCommandParsesCorrectly(): void
    {
        $commentBody = "@gstraccini batch copy workflow ci.yml";
        $pattern = "/@gstraccini\sbatch\scopy\sworkflow\s([a-zA-Z0-9_.-]+)(?:\s+language:([a-zA-Z0-9_.-]+))?(?:\s+file:([a-zA-Z0-9_\/.-]+))?(?:\s+account:([a-zA-Z0-9_.-]+))?/";

        preg_match($pattern, $commentBody, $matches);

        $this->assertCount(2, $matches);
        $this->assertEquals('ci.yml', $matches[1]);
    }

    public function testBatchCopyWorkflowWithLanguageFilter(): void
    {
        $commentBody = "@gstraccini batch copy workflow build.yml language:javascript";
        $pattern = "/@gstraccini\sbatch\scopy\sworkflow\s([a-zA-Z0-9_.-]+)(?:\s+language:([a-zA-Z0-9_.-]+))?(?:\s+file:([a-zA-Z0-9_\/.-]+))?(?:\s+account:([a-zA-Z0-9_.-]+))?/";

        preg_match($pattern, $commentBody, $matches);

        $this->assertCount(3, $matches);
        $this->assertEquals('build.yml', $matches[1]);
        $this->assertEquals('javascript', $matches[2]);
    }

    public function testBatchCopyWorkflowWithMultipleFilters(): void
    {
        $commentBody = "@gstraccini batch copy workflow deploy.yml language:python file:ci/config.yml";
        $pattern = "/@gstraccini\sbatch\scopy\sworkflow\s([a-zA-Z0-9_.-]+)(?:\s+language:([a-zA-Z0-9_.-]+))?(?:\s+file:([a-zA-Z0-9_\/.-]+))?(?:\s+account:([a-zA-Z0-9_.-]+))?/";

        preg_match($pattern, $commentBody, $matches);

        $this->assertCount(4, $matches);
        $this->assertEquals('deploy.yml', $matches[1]);
        $this->assertEquals('python', $matches[2]);
        $this->assertEquals('ci/config.yml', $matches[3]);
    }

    public function testBatchCopyWorkflowWithAccountFilter(): void
    {
        $commentBody = "@gstraccini batch copy workflow ci.yml account:myorg";
        $pattern = "/@gstraccini\sbatch\scopy\sworkflow\s([a-zA-Z0-9_.-]+)(?:\s+language:([a-zA-Z0-9_.-]+))?(?:\s+file:([a-zA-Z0-9_\/.-]+))?(?:\s+account:([a-zA-Z0-9_.-]+))?/";

        preg_match($pattern, $commentBody, $matches);

        $this->assertCount(3, $matches);
        $this->assertEquals('ci.yml', $matches[1]);
        $this->assertEquals('myorg', $matches[4]);
    }

    public function testBatchCopyWorkflowWithAllFilters(): void
    {
        $commentBody = "@gstraccini batch copy workflow test.yml language:typescript file:package.json account:myorg";
        $pattern = "/@gstraccini\sbatch\scopy\sworkflow\s([a-zA-Z0-9_.-]+)(?:\s+language:([a-zA-Z0-9_.-]+))?(?:\s+file:([a-zA-Z0-9_\/.-]+))?(?:\s+account:([a-zA-Z0-9_.-]+))?/";

        preg_match($pattern, $commentBody, $matches);

        $this->assertCount(5, $matches);
        $this->assertEquals('test.yml', $matches[1]);
        $this->assertEquals('typescript', $matches[2]);
        $this->assertEquals('package.json', $matches[3]);
        $this->assertEquals('myorg', $matches[4]);
    }
}