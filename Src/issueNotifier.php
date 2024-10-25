<?php

require_once "config/config.php";
use GuiBranco\Pancake\Request;

class IssueNotifier {
    private $api;
    private $db;

    public function __construct($api, $db) {
        $this->api = $api;
        $this->db = $db;
    }

    public function checkInactiveIssues() {
        $issues = $this->api->getAssignedIssues();
        foreach ($issues as $issue) {
            $lastActivity = $this->getLastActivityDate($issue);
            if ($this->isInactive($lastActivity)) {
                $this->updateLabels($issue);
                $this->notifyAssignee($issue);
            }
        }
    }

    private function getLastActivityDate($issue) {
        // Logic to get the last activity date from issue comments, commits, or pull requests
    }

    private function isInactive($lastActivity) {
        $inactivePeriod = 14 * 24 * 60 * 60; // 14 days in seconds
        return (time() - strtotime($lastActivity)) > $inactivePeriod;
    }

    private function updateLabels($issue) {
        // Logic to update labels: remove 'WIP' and add 'Waiting assignee'
    }

    private function notifyAssignee($issue) {
        // Logic to send notification to the assignee
    }
}

$api = new Request();
$db = new Database();
$notifier = new IssueNotifier($api, $db);
$notifier->checkInactiveIssues();

?>
