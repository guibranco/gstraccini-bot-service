<?php

namespace GuiBranco\GStracciniBot\Library;

class MarkdownGroupCheckboxValidator
{
    public function validateCheckboxes(string $prBody): array
    {
        $groupPattern = '/(###?[^\n]+)(?:\n- \[(x| )\] [^\n]+)+/i';
        $checkboxPattern = '/- \[(x| )\] (.+)/i';

        $report = [
            'groups' => [],
            'errors' => [],
        ];

        if (preg_match_all($groupPattern, $prBody, $groupMatches, PREG_SET_ORDER)) {
            foreach ($groupMatches as $groupMatch) {
                $groupTitle = trim($groupMatch[1]);

                preg_match_all($checkboxPattern, $groupMatch[0], $checkboxMatches, PREG_SET_ORDER);

                $groupResult = [
                    'group' => $groupTitle,
                    'checked' => [],
                    'unchecked' => [],
                ];

                $hasChecked = false;
                foreach ($checkboxMatches as $checkboxMatch) {
                    $checkboxText = trim($checkboxMatch[2]);
                    if (strtolower($checkboxMatch[1]) === 'x') {
                        $groupResult['checked'][] = $checkboxText;
                        $hasChecked = true;
                    } else {
                        $groupResult['unchecked'][] = $checkboxText;
                    }
                }

                if (!$hasChecked) {
                    $report['errors'][] = "No checkbox selected in group: $groupTitle";
                }

                $report['groups'][] = $groupResult;
            }
        } else {
            $report['errors'][] = 'No groups or checkboxes found in the PR body.';
        }

        return $report;
    }

    public function generateReport(array $validationResult): string
    {
        if (isset($validationResult['errors']) && !empty($validationResult['errors'])) {
            return implode("\n", $validationResult['errors']);
        }

        $report = "Checkbox Validation Report:\n";

        foreach ($validationResult['groups'] as $group) {
            $report .= "\n{$group['group']}\n";

            if (!empty($group['checked'])) {
                $report .= "Checked items:\n";
                foreach ($group['checked'] as $checkedItem) {
                    $report .= "- $checkedItem\n";
                }
            } else {
                $report .= "No checked items.\n";
            }

            if (!empty($group['unchecked'])) {
                $report .= "Unchecked items:\n";
                foreach ($group['unchecked'] as $uncheckedItem) {
                    $report .= "- $uncheckedItem\n";
                }
            }
        }

        return $report;
    }
}
