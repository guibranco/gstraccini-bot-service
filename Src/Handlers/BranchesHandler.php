<?php

namespace GuiBranco\GStracciniBot\Handlers;

/**
 * Handles branch events shared by the HTTP webhook entry point
 * (Src/branches.php) and the queue worker (Src/Workers/branches.php).
 */
class BranchesHandler implements IHandler
{
    public function handleItem($branch)
    {
        global $logStream;

        echo "https://github.com/{$branch->RepositoryOwner}/{$branch->RepositoryName}/tree/{$branch->Ref}:\n\n";

        $logStream?->info(
            "Processing branch event: {$branch->Event} on {$branch->Ref}",
            ['repo' => "{$branch->RepositoryOwner}/{$branch->RepositoryName}", 'event' => $branch->Event, 'ref' => $branch->Ref, 'sender' => $branch->SenderLogin ?? null],
            "branches",
            $branch->DeliveryIdText
        );

        $token = generateInstallationToken($branch->InstallationId, $branch->RepositoryName);

        $metadata = array(
            "token" => $token,
            "repoUrl" => "repos/" . $branch->RepositoryOwner . "/" . $branch->RepositoryName
        );

        $data = $this->getReferencedIssueByBranch($metadata, $branch);
        if (!isset($data->data)) {
            $logStream?->info(
                "No linked issues found for branch {$branch->Ref}",
                ['repo' => "{$branch->RepositoryOwner}/{$branch->RepositoryName}"],
                "branches",
                $branch->DeliveryIdText
            );
            return;
        }
        $issues = $data->data->repository->issues->nodes;

        foreach ($issues as $issue) {
            if ($this->processIssue($issue, $branch, $metadata)) {
                break;
            }
        }
    }

    private function processIssue($issue, $branch, $metadata)
    {
        $linkedBranches = $issue->linkedBranches->nodes;
        foreach ($linkedBranches as $linkedBranch) {
            if ($this->processLinkedBranch($linkedBranch, $issue, $branch, $metadata)) {
                return true;
            }
        }

        return false;
    }

    private function processLinkedBranch($linkedBranch, $issue, $branch, $metadata)
    {
        if (
            $linkedBranch === null ||
            $linkedBranch->ref === null ||
            $linkedBranch->ref->name === null
        ) {
            return false;
        }
        if ($linkedBranch->ref->name == $branch->Ref) {
            $metadata["issueUrl"] = $metadata["repoUrl"] . "/issues/" . $issue->number;
            return $this->processLabels($issue, $branch, $metadata);
        }
    }

    private function processLabels($issue, $branch, $metadata)
    {
        $found = false;
        foreach ($issue->labels->nodes as $label) {
            if ($label == null || $label->name == null) {
                continue;
            }

            if ($label->name == "🛠 WIP") {
                $found = true;
                break;
            }
        }

        if (!$found && $branch->Event == "create") {
            $body = array("labels" => ["🛠 WIP"]);
            doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/labels", $body, "POST");
            $this->processAddAssignee($issue, $branch, $metadata);
        }

        if ($found && $branch->Event == "delete") {
            $url = $metadata["issueUrl"] . "/labels/🛠%20WIP";
            doRequestGitHub($metadata["token"], $url, null, "DELETE");
            $this->processRemoveAssignee($issue, $branch, $metadata);
        }

        return true;
    }

    private function processAddAssignee($issue, $branch, $metadata)
    {
        if ($issue->assignees != null && count($issue->assignees->nodes) > 0) {
            return;
        }

        $body = array("assignees" => [$branch->SenderLogin]);
        doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/assignees", $body, "POST");
    }

    private function processRemoveAssignee($issue, $branch, $metadata)
    {
        if ($issue->state != "OPEN") {
            return;
        }

        if ($issue->assignees == null || count($issue->assignees->nodes) == 0) {
            return;
        }

        $body = array("assignees" => [$branch->SenderLogin]);
        doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/assignees", $body, "DELETE");
    }

    private function getReferencedIssueByBranch($metadata, $branch)
    {
        $referencedIssueQuery = array(
            "query" => "query {
            repository(owner: \"" . $branch->RepositoryOwner . "\", name: \"" . $branch->RepositoryName . "\") {
              issues(states: [OPEN], last: 100){
                nodes {
                    id,
                    number,
                    state,
                    title,
                    assignees (first: 1) {
                        nodes {
                            login
                        }
                    },
                    labels (first: 100) {
                        nodes {
                            name
                        }
                    },
                    linkedBranches (first: 10) {
                        nodes {
                            ref {
                                name
                            }
                        }
                    }
                }
              }
            }
          }"
        );
        $referencedIssueResponse = doRequestGitHub($metadata["token"], "graphql", $referencedIssueQuery, "POST");
        return json_decode($referencedIssueResponse->getBody());
    }
}
