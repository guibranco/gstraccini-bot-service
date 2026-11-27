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
        if ($body === null || trim($body) === '') {
            return null;
        }

        $report = (new MarkdownGroupCheckboxValidator())->validateCheckboxes($body);
        foreach ($report["groups"] as $group) {
            if (preg_match(self::GROUP_TITLE_PATTERN, $group["group"]) !== 1) {
                continue;
            }

            $checked = array_map(static fn (string $text): string => strtolower(trim($text)), $group["checked"]);
            if ($checked === ["yes"]) {
                return true;
            }

            if ($checked === ["no"]) {
                return false;
            }

            return null;
        }

        return null;

    }//end detect()


}//end class
