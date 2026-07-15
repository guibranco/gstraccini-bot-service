<?php

namespace Src\Library;

use Src\Library\GitHub;
use Src\Library\LabelService;

class ProcessingManager
{
    private $github;
    private $labelService;

    public function __construct(GitHub $github, LabelService $labelService)
    {
        $this->github = $github;
        $this->labelService = $labelService;
    }

    public function processLabels(int $issueId, array $labels): void
    {
        $issue = $this->github->getIssue($issueId);

        $this->github->createComment($issue->getId(), 'Suggested labels:');
        $this->github->createComment($issue->getId(), implode("\n", $labels));

        $this->github->createComment($issue->getId(), 'Accept or reject the suggested labels by replying with `/labels accept` or `/labels reject`');

        $this->github->createComment($issue->getId(), 'Note: If you accept the labels, they will be assigned to the issue');

        $this->labelService->assignLabels($issue->getId(), $labels);
    }
}