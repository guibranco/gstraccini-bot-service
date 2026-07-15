<?php

namespace Src\Workers;

use Src\Library\GitHub;
use Src\Library\ProcessingManager;

class IssuesWorker
{
    private $github;
    private $processingManager;

    public function __construct(GitHub $github, ProcessingManager $processingManager)
    {
        $this->github = $github;
        $this->processingManager = $processingManager;
    }

    public function handleIssueCreated(int $issueId): void
    {
        $this->processingManager->processLabels($issueId, []);
    }
}