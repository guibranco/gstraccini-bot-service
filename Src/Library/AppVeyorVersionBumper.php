<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Reads and bumps the major part of the top-level `version:` of an
 * `appveyor.yml` file (e.g. `1.4.{build}` becomes `2.0.{build}`).
 *
 * Numeric parts after the major are reset to 0; placeholders such as
 * `{build}` are kept as they are. Only the top-level key is handled.
 */
class AppVeyorVersionBumper
{
    /**
     * A top-level `version:` line: its key, its optional quote, the version and the rest of the line.
     */
    private const VERSION_LINE = '/^(version[ \t]*:[ \t]*)([\'"]?)([^\s\'"#]+)\2([^\n]*)$/m';


    /**
     * The top-level version format of the file.
     *
     * @param string $yaml The content of the appveyor.yml file.
     *
     * @return string|null The version (e.g. `1.4.{build}`), null when there is none.
     */
    public function readVersion(string $yaml): ?string
    {
        if (preg_match(self::VERSION_LINE, str_replace("\r", "", $yaml), $matches) !== 1) {
            return null;
        }

        return $matches[3];

    }//end readVersion()


    /**
     * The major part of a version format.
     *
     * @param string $version The version format (e.g. `1.4.{build}`).
     *
     * @return integer|null The major, null when the version doesn't start with a number.
     */
    public function majorOf(string $version): ?int
    {
        if (preg_match('/^(\d+)(?:\.|$)/', $version, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];

    }//end majorOf()


    /**
     * The version format with the next major.
     *
     * @param string $version The version format (e.g. `1.4.{build}`).
     *
     * @return string|null The bumped format (e.g. `2.0.{build}`), null when the major can't be read.
     */
    public function nextMajor(string $version): ?string
    {
        $major = $this->majorOf($version);
        if ($major === null) {
            return null;
        }

        $parts    = explode(".", $version);
        $parts[0] = (string) ($major + 1);
        $count    = count($parts);
        for ($index = 1; $index < $count; $index++) {
            if (ctype_digit($parts[$index]) === true) {
                $parts[$index] = "0";
            }
        }

        return implode(".", $parts);

    }//end nextMajor()


    /**
     * Bumps the major of the file's top-level version.
     *
     * @param string $yaml The content of the appveyor.yml file.
     *
     * @return array|null The new content and the versions (`content`, `from`, `to`), null when
     *                    the file has no top-level version starting with a number.
     */
    public function bumpMajor(string $yaml): ?array
    {
        $from = $this->readVersion($yaml);
        if ($from === null) {
            return null;
        }

        $next = $this->nextMajor($from);
        if ($next === null) {
            return null;
        }

        $content = preg_replace_callback(
            self::VERSION_LINE,
            static fn (array $matches): string => sprintf("%s%s%s%s%s", $matches[1], $matches[2], $next, $matches[2], $matches[4]),
            $yaml,
            1
        );

        $result            = [];
        $result["content"] = $content;
        $result["from"]    = $from;
        $result["to"]      = $next;

        return $result;

    }//end bumpMajor()


}//end class
