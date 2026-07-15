<?php

namespace Src\Workers;

use Src\Library\GitHub;
use Src\Library\OpenAI;
use Src\Library\LabelService;
use Src\Library\ProcessingManager;

class Index
{
    public function run(): void
    {
        $github = new GitHub();
        $openAI = new OpenAI(new HttpClient());
        $labelService = new LabelService();
        $processingManager = new ProcessingManager($github, $labelService);

        $issuesWorker = new IssuesWorker($github, $processingManager);
        $labelsWorker = new LabelsWorker($github, $openAI, $labelService, $processingManager);

        $github->listenForIssueCreated($issuesWorker, $labelsWorker);
    }
}