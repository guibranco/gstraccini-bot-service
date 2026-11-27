<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

/**
 * Builds unified diffs, as GitHub returns them, for the tests of the review effort.
 */
class ReviewEffortDiffFixtures
{
    /** A plain line of code, with no branching. */
    public const PLAIN_CODE = '$total = 1;';

    /** A line of code with two branching tokens. */
    public const BRANCHING_CODE = 'if ($first && $second) {';


    /**
     * The diff of one file, one hunk per entry of $hunks.
     *
     * @param string     $path   The file path.
     * @param string[][] $hunks  The changed lines of each hunk, each starting with "+" or "-".
     * @param string[]   $header The header lines before "index" ("new file mode 100644", ...).
     *
     * @return string
     */
    public function fileDiff(string $path, array $hunks, array $header): string
    {
        $lines   = [];
        $lines[] = "diff --git a/{$path} b/{$path}";
        array_push($lines, ...$header);
        $lines[] = "index 111..222 100644";
        $lines[] = "--- a/{$path}";
        $lines[] = "+++ b/{$path}";
        foreach ($hunks as $index => $hunk) {
            $start   = (($index * 10) + 1);
            $lines[] = "@@ -{$start},3 +{$start},3 @@";
            $lines[] = " context";
            array_push($lines, ...$hunk);
        }

        return sprintf("%s\n", implode("\n", $lines));

    }//end fileDiff()


    /**
     * The diff of one file with one hunk of added lines.
     *
     * @param string  $path  The file path.
     * @param integer $count The number of added lines.
     * @param string  $code  The text of each added line.
     *
     * @return string
     */
    public function addedDiff(string $path, int $count, string $code): string
    {
        return $this->fileDiff($path, [$this->added($count, $code)], []);

    }//end addedDiff()


    /**
     * Added lines of a hunk.
     *
     * @param integer $count The number of lines.
     * @param string  $code  The text of each line.
     *
     * @return string[]
     */
    public function added(int $count, string $code): array
    {
        return array_fill(0, $count, "+{$code}");

    }//end added()


    /**
     * Deleted lines of a hunk.
     *
     * @param integer $count The number of lines.
     * @param string  $code  The text of each line.
     *
     * @return string[]
     */
    public function removed(int $count, string $code): array
    {
        return array_fill(0, $count, "-{$code}");

    }//end removed()


    /**
     * The diff of a file moved without changes.
     *
     * @param string $from        The old path.
     * @param string $destination The new path.
     *
     * @return string
     */
    public function renameDiff(string $from, string $destination): string
    {
        return "diff --git a/{$from} b/{$destination}\nsimilarity index 100%\nrename from {$from}\nrename to {$destination}\n";

    }//end renameDiff()


    /**
     * The diff of a changed binary file.
     *
     * @param string $path The file path.
     *
     * @return string
     */
    public function binaryDiff(string $path): string
    {
        return "diff --git a/{$path} b/{$path}\nindex 111..222 100644\nBinary files a/{$path} and b/{$path} differ\n";

    }//end binaryDiff()


}//end class
