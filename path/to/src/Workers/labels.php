<?php

namespace Src\Workers;

use Src\Library\GitHub;
use Src\Library\OpenAI;
use Src\Library\LabelService;
use Src\Library\ProcessingManager;

class LabelsWorker
{
    private $github;
    private $openAI;
    private $labelService;
    private $processingManager;

    public function __construct(GitHub $github, OpenAI $openAI, LabelService $labelService, ProcessingManager $processingManager)
    {
        $this->github = $github;
        $this->openAI = $openAI;
        $this->labelService = $labelService;
        $this->processingManager = $processingManager;
    }

    public function handleIssueCreated(int $issueId): void
    {
        $issue = $this->github->getIssue($issueId);
        $labels = $this->github->getLabels($issue->getRepository()->getId());

        $suggestedLabels = $this->openAI->suggestLabels($issue->getTitle(), $issue->getBody(), $labels);

        $this->processingManager->processLabels($issueId, $suggestedLabels);
    }
}