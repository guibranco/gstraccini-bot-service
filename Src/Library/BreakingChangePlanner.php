<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Works out what a breaking change pull request still needs to bump the major
 * version, by looking at the CI setups of the repository through the GitHub API:
 *
 * - GitVersion (a `GitVersion.yml` file): a commit carrying `+semver: major`;
 * - AppVeyor (an `appveyor.yml` file): a bigger major in its `version:` than
 *   on the base branch.
 */
class BreakingChangePlanner
{
    /**
     * The GitVersion configuration files at the repository root, comma-separated, in order of preference.
     */
    public const GITVERSION_FILES = "GitVersion.yml,GitVersion.yaml,gitversion.yml";

    /**
     * The AppVeyor configuration files at the repository root, comma-separated, in order of preference.
     */
    public const APPVEYOR_FILES = "appveyor.yml,.appveyor.yml";

    /**
     * A GitVersion directive asking for a major bump.
     */
    private const MAJOR_DIRECTIVE = '/\+semver:\s?(major|breaking)\b/i';


    /**
     * Plans the major version bump of a pull request.
     *
     * @param array    $metadata       Metadata for the GitHub API requests (`token`, `owner`, `repo`).
     * @param string   $headRef        The pull request head branch.
     * @param string   $baseRef        The pull request base branch.
     * @param string[] $commitMessages The pull request title and its commit messages.
     *
     * @return BreakingChangePlan
     */
    public function plan(array $metadata, string $headRef, string $baseRef, array $commitMessages): BreakingChangePlan
    {
        $usesGitVersion    = $this->findFile($metadata, self::GITVERSION_FILES, $headRef) !== null;
        $hasMajorDirective = $this->hasMajorDirective($commitMessages);

        $appVeyor = $this->findFile($metadata, self::APPVEYOR_FILES, $headRef);
        if ($appVeyor === null) {
            return new BreakingChangePlan($usesGitVersion, $hasMajorDirective, null, null, null, null, null);
        }

        $bumper      = new AppVeyorVersionBumper();
        $headVersion = $bumper->readVersion($appVeyor["content"]);
        $bumped      = null;
        if ($this->isAppVeyorBumped($metadata, $appVeyor, $baseRef) === false) {
            $bumped = $bumper->bumpMajor($appVeyor["content"]);
        }

        return new BreakingChangePlan(
            $usesGitVersion,
            $hasMajorDirective,
            $appVeyor["path"],
            $appVeyor["sha"],
            $headVersion,
            ($bumped["to"] ?? null),
            ($bumped["content"] ?? null)
        );

    }//end plan()


    /**
     * Whether a message asks GitVersion for a major bump.
     *
     * @param string[] $messages The pull request title and its commit messages.
     *
     * @return boolean
     */
    public function hasMajorDirective(array $messages): bool
    {
        foreach ($messages as $message) {
            if (preg_match(self::MAJOR_DIRECTIVE, (string) $message) === 1) {
                return true;
            }
        }

        return false;

    }//end hasMajorDirective()


    /**
     * Whether the AppVeyor version of the head branch already has a bigger major than the base branch's.
     *
     * @param array  $metadata Metadata for the GitHub API requests.
     * @param array  $appVeyor The AppVeyor file on the head branch.
     * @param string $baseRef  The pull request base branch.
     *
     * @return boolean
     */
    private function isAppVeyorBumped(array $metadata, array $appVeyor, string $baseRef): bool
    {
        $bumper    = new AppVeyorVersionBumper();
        $headMajor = $bumper->majorOf((string) $bumper->readVersion($appVeyor["content"]));
        $base      = $this->readFile($metadata, $appVeyor["path"], $baseRef);
        if ($headMajor === null || $base === null) {
            return false;
        }

        $baseMajor = $bumper->majorOf((string) $bumper->readVersion($base["content"]));

        return $baseMajor !== null && $headMajor > $baseMajor;

    }//end isAppVeyorBumped()


    /**
     * The first of some files that exists on a branch.
     *
     * @param array  $metadata Metadata for the GitHub API requests.
     * @param string $paths    The file paths, comma-separated, in order of preference.
     * @param string $ref      The branch.
     *
     * @return array|null The file (`path`, `sha`, `content`), null when none exists.
     */
    private function findFile(array $metadata, string $paths, string $ref): ?array
    {
        foreach (explode(",", $paths) as $path) {
            $file = $this->readFile($metadata, $path, $ref);
            if ($file !== null) {
                return $file;
            }
        }

        return null;

    }//end findFile()


    /**
     * Reads a file of the repository on a branch.
     *
     * @param array  $metadata Metadata for the GitHub API requests.
     * @param string $path     The file path.
     * @param string $ref      The branch.
     *
     * @return array|null The file (`path`, `sha`, `content`), null when it doesn't exist or can't be read.
     */
    private function readFile(array $metadata, string $path, string $ref): ?array
    {
        $encodedRef = rawurlencode($ref);
        $url        = "repos/{$metadata["owner"]}/{$metadata["repo"]}/contents/{$path}?ref={$encodedRef}";
        $response   = doRequestGitHub($metadata["token"], $url, null, "GET");
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $file    = json_decode((string) $response->getBody());
        $content = false;
        if (is_object($file) === true && isset($file->content) === true && ($file->type ?? "file") === "file") {
            $content = base64_decode(str_replace(["\n", "\r"], "", $file->content), true);
        }

        if ($content === false) {
            return null;
        }

        $result            = [];
        $result["path"]    = $path;
        $result["sha"]     = ($file->sha ?? null);
        $result["content"] = $content;

        return $result;

    }//end readFile()


}//end class
