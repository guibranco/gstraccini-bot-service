<?php

require_once "config/config.php";

use GuiBranco\GStracciniBot\lib\HealthChecks;

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
        $linkedBranches = $node->linkedBranches->nodes;
        foreach ($linkedBranches as $linkedBranch) {
            if ($linkedBranch === null || $linkedBranch->ref === null) {
                continue;
            }            
            if ($linkedBranch->ref->name == $branch->Ref) {
                $found = false;
                foreach ($node->labels as $label) {
                    if ($label->name == "WIP") {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $body = array("labels" => ["WIP"]);
                    doRequestGitHub($metadata["token"], $metadata["repoUrl"] . "/issues/" . $node->number . "/labels", $body, "POST");
                }

                break 2;
            }
        }
    }
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
