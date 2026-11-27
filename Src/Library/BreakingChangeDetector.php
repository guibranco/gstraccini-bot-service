<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Reads the "Does this introduce a breaking change?" Yes/No checkbox group of
 * a pull request description (the pull request template of the estate).
 *
 * The group is found by its heading mentioning a breaking change, whatever
 * emoji or wording surrounds it. That exactly one of Yes/No is ticked is
 * enforced by the description check (MarkdownGroupCheckboxValidator); this
 * class only says which one, and stays silent when the answer is unclear.
 */
class BreakingChangeDetector
{
    private const GROUP_TITLE_PATTERN = '/breaking[\s_-]*change/i';


    /**
     * Whether the description says the pull request introduces a breaking change.
     *
     * @param string|null $body The pull request description.
     *
     * @return boolean|null True when "Yes" is the only box ticked, false when "No" is,
     *                      null when there is no such group or the answer is unclear.
     */
    public function detect(?string $body): ?bool
    {
        $group = null;
        if ($body !== null && trim($body) !== '') {
            $group = $this->findGroup($body);
        }

        if ($group === null) {
            return null;
        }

        return $this->answerOf($group["checked"]);

    }//end detect()


    /**
     * The breaking change checkbox group of a description.
     *
     * @param string $body The pull request description.
     *
     * @return array|null The group, as MarkdownGroupCheckboxValidator reports it, null when there is none.
     */
    private function findGroup(string $body): ?array
    {
        $report = (new MarkdownGroupCheckboxValidator())->validateCheckboxes($body);
        foreach ($report["groups"] as $group) {
            if (preg_match(self::GROUP_TITLE_PATTERN, $group["group"]) === 1) {
                return $group;
            }
        }

        return null;

    }//end findGroup()


    /**
     * The answer given by the boxes ticked in the group.
     *
     * @param string[] $checked The labels of the boxes ticked.
     *
     * @return boolean|null True for "Yes" alone, false for "No" alone, null otherwise.
     */
    private function answerOf(array $checked): ?bool
    {
        if (count($checked) !== 1) {
            return null;
        }

        return match (strtolower(trim($checked[0]))) {
            "yes" => true,
            "no" => false,
            default => null,
        };

    }//end answerOf()


}//end class
