<?php

namespace GuiBranco\GStracciniBot\Handlers;

/**
 * Handles issue events shared by the HTTP webhook entry point
 * (Src/issues.php) and the queue worker (Src/Workers/issues.php).
 */
class IssuesHandler implements IHandler
{
    public function handleItem($issue)
    {
        global $logStream;

        echo "https://github.com/{$issue->RepositoryOwner}/{$issue->RepositoryName}/issues/{$issue->Number}:\n\n";

        $logStream?->info(
            "Processing issue #{$issue->Number}",
            ['repo' => "{$issue->RepositoryOwner}/{$issue->RepositoryName}", 'sender' => $issue->Sender ?? null],
            "issues",
            $issue->DeliveryIdText
        );

        $token = generateInstallationToken($issue->InstallationId, $issue->RepositoryName);

        $repoPrefix = "repos/" . $issue->RepositoryOwner . "/" . $issue->RepositoryName;
        $metadata = array(
            "token" => $token,
            "repoUrl" => $repoPrefix,
            "assigneesUrl" => $repoPrefix . "/issues/" . $issue->Number . "/assignees",
            "collaboratorsUrl" => $repoPrefix . "/collaborators",
            "issueUrl" => $repoPrefix . "/issues/" . $issue->Number,
        );

        $issueResponse = doRequestGitHub($metadata["token"], $metadata["issueUrl"], null, "GET");
        $issueUpdated = json_decode($issueResponse->getBody());

        if ($issueUpdated->state === "closed") {
            $logStream?->info(
                "Issue #{$issue->Number} is closed — removing labels",
                ['repo' => "{$issue->RepositoryOwner}/{$issue->RepositoryName}"],
                "issues",
                $issue->DeliveryIdText
            );
            $this->removeLabels($issueUpdated, $metadata, true);
            if ($issue->State === "OPEN") {
                updateStateToClosedInTable("issues", $issue->Sequence);
            }

            return;
        }

        if ($issueUpdated->assignee != null) {
            $this->removeLabels($issueUpdated, $metadata);
            return;
        }

        $repositoryResponse = doRequestGitHub($metadata["token"], $metadata["repoUrl"], null, "GET");
        $repository = json_decode($repositoryResponse->getBody());

        $collaboratorsResponse = doRequestGitHub($metadata["token"], $metadata["collaboratorsUrl"], null, "GET");
        $collaborators = json_decode($collaboratorsResponse->getBody(), true);
        $collaboratorsLogins = array_column($collaborators, "login");

        $autoAssignSenders = array("pixeebot[bot]");

        if ($repository->private || in_array($issueUpdated->user->login, $autoAssignSenders, true)) {
            $body = array("assignees" => $collaboratorsLogins);
            doRequestGitHub($metadata["token"], $metadata["assigneesUrl"], $body, "POST");
        }

        $this->addLabels($issueUpdated, $collaboratorsLogins, $metadata);
        $logStream?->info(
            "Issue #{$issue->Number} processed",
            ['repo' => "{$issue->RepositoryOwner}/{$issue->RepositoryName}", 'user' => $issueUpdated->user->login],
            "issues",
            $issue->DeliveryIdText
        );
    }

    private function addLabels($issueUpdated, $collaboratorsLogins, $metadata)
    {
        $labels = [];
        if (!in_array($issueUpdated->user->login, $collaboratorsLogins) && $issueUpdated->user->login !== "pixeebot[bot]") {
            $labels[] = "🚦 awaiting triage";
        }

        if ($issueUpdated->user->type === "Bot") {
            $labels[] = "🤖 bot";
        }

        if ($issueUpdated->user->login === "pixeebot[bot]") {
            $labels = array_merge($labels, ["🛠️ automation", "📊 dashboard", "♻️ code quality", "🤖 pixeebot"]);
        }

        if (count($labels) > 0) {
            $body = array("labels" => $labels);
            doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/labels", $body, "POST");
        }
    }

    private function removeLabels($issueUpdated, $metadata, $includeWip = false)
    {
        $labelsLookup = [
            "🚦 awaiting triage",
            "⏳ awaiting response"
        ];
        if ($includeWip === true) {
            $labelsLookup[] = "🛠 WIP";
        }

        $labels = array_column($issueUpdated->labels, "name");
        $intersect = array_intersect($labelsLookup, $labels);

        foreach ($intersect as $label) {
            $label = str_replace(" ", "%20", $label);
            $url = $metadata["issueUrl"] . "/labels/{$label}";
            doRequestGitHub($metadata["token"], $url, null, "DELETE");
        }
    }
}
