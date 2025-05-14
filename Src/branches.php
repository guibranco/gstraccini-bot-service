<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\ProcessingManager;
use GuiBranco\Pancake\GUIDv4;
use GuiBranco\Pancake\HealthChecks;

function handleItem($branch)
{
    echo "https://github.com/{$branch->RepositoryOwner}/{$branch->RepositoryName}/tree/{$branch->Ref}:\n\n";

    $token = generateInstallationToken($branch->InstallationId, $branch->RepositoryName);

    $metadata = array(
        "token" => $token,
        "repoUrl" => "repos/" . $branch->RepositoryOwner . "/" . $branch->RepositoryName
    );

    $data = getReferencedIssueByBranch($metadata, $branch);
    if (!isset($data->data)) {
        return;
    }
    $issues = $data->data->repository->issues->nodes;

    foreach ($issues as $issue) {
        if (processIssue($issue, $branch, $metadata)) {
            break;
        }
    }
}

function processIssue($issue, $branch, $metadata)
{
    $linkedBranches = $issue->linkedBranches->nodes;
    foreach ($linkedBranches as $linkedBranch) {
        if (processLinkedBranch($linkedBranch, $issue, $branch, $metadata)) {
            return true;
        }
    }

    return false;
}

function processLinkedBranch($linkedBranch, $issue, $branch, $metadata)
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
        return processLabels($issue, $branch, $metadata);
    }
}

function processLabels($issue, $branch, $metadata)
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
        processAddAssignee($issue, $branch, $metadata);
    }

    if ($found && $branch->Event == "delete") {
        $url = $metadata["issueUrl"] . "/labels/🛠%20WIP";
        doRequestGitHub($metadata["token"], $url, null, "DELETE");
        processRemoveAssignee($issue, $branch, $metadata);
    }

    return true;
}

function processAddAssignee($issue, $branch, $metadata)
{
    if ($issue->assignees != null && count($issue->assignees->nodes) > 0) {
        return;
    }

    $body = array("assignees" => [$branch->SenderLogin]);
    doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/assignees", $body, "POST");
}

function processRemoveAssignee($issue, $branch, $metadata)
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

function getReferencedIssueByBranch($metadata, $branch)
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

$healthCheck = new HealthChecks($healthChecksIoBranches, GUIDv4::random());
$processor = new ProcessingManager("branches", $healthCheck, $logger);
$processor->initialize("handleItem", 55);
