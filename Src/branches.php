<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\Library\HealthChecks;

function handleBranch($branch)
{
    $token = generateInstallationToken($branch->InstallationId, $branch->RepositoryName);

    $metadata = array(
        "token" => $token,
        "repoUrl" => "repos/" . $branch->RepositoryOwner . "/" . $branch->RepositoryName
    );

    $data = getReferencedIssueByBranch($metadata, $branch);
    if (!isset($data->data)) {
        return;
    }
    $nodes = $data->data->repository->issues->nodes;

    foreach ($nodes as $node) {
        if (processNode($node, $branch, $metadata)) {
            break;
        }
    }
}

function processNode($node, $branch, $metadata)
{
    $linkedBranches = $node->linkedBranches->nodes;
    foreach ($linkedBranches as $linkedBranch) {
        if (processLinkedBranch($linkedBranch, $node, $branch, $metadata)) {
            return true;
        }
    }

    return false;
}

function processLinkedBranch($linkedBranch, $node, $branch, $metadata)
{
    if (
        $linkedBranch === null ||
        $linkedBranch->ref === null ||
        $linkedBranch->ref->name === null
    ) {
        return false;
    }
    if ($linkedBranch->ref->name == $branch->Ref) {
        $metadata["issueUrl"] = $metadata["repoUrl"] . "/issues/" . $node->number;
        $found = false;
        foreach ($node->labels as $label) {
            if ($label->name == "WIP") {
                $found = true;
                break;
            }
        }

        if (!$found && $branch->Event == "create") {
            $body = array("labels" => ["WIP"]);
            doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/labels", $body, "POST");
        }

        if ($found && $branch->Event == "delete") {
            doRequestGitHub($metadata["token"], $metadata["issueUrl"] . "/labels/WIP", null, "DELETE");
        }

        return true;
    }

    return false;
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
                    status,
                    title,
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
    return json_decode($referencedIssueResponse->body);
}

function main()
{
    $branches = readTable("github_branches");
    foreach ($branches as $branch) {
        handleBranch($branch);
        updateTable("github_branches", $branch->Sequence);
    }
}

$healthCheck = new HealthChecks($healthChecksIoBranches);
$healthCheck->start();
main();
$healthCheck->end();
