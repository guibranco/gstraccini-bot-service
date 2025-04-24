<?php
/**
 * Auto-labels PRs for dependency file changes.
 *
 * PHP version 7.4+
 *
 * @category Automation
 * @package  GStracciniBot
 * @link     https://github.com/guibranco/gstraccini-bot-service
 * @license   MIT
 * @author GuiBranco <gui.branco@example.com>
 */

namespace GuiBranco\GStracciniBot\Library;

/**
 * @category Automation
 * Class DependencyAutoLabeler
 * @license  MIT
 * Provides functionality to auto-label pull requests
 * based on dependency file changes.
 * @link https://github.com/guibranco/gstraccini-bot-service
 * @package GStracciniBot
 */
class DependencyAutoLabeler
{
    private static $dependencyMapping = [
        '.csproj' => ['nuget'],
        'CMakeLists.txt' => ['cmake'],
        'conanfile.txt' => ['conan'],
        'shard.yml' => ['shards'],
        'pubspec.yaml' => ['pub'],
        'mix.exs' => ['mix'],
        'elm.json' => ['elm'],
        '.fsproj' => ['nuget'],
        'paket.dependencies' => ['paket', 'nuget'],
        'go.mod' => ['go-mod'],
        'cabal.config' => ['cabal'],
        'package.yaml' => ['stack'],
        'pom.xml' => ['maven'],
        'build.gradle' => ['gradle'],
        'package.json' => ['npm', 'yarn'],
        'Project.toml' => ['julia'],
        'Manifest.toml' => ['julia'],
        'build.gradle.kts' => ['gradle'],
        '.rockspec' => ['luarocks'],
        'Podfile' => ['cocoapods'],
        'Makefile.PL' => ['cpan'],
        'Build.PL' => ['cpan'],
        'composer.json' => ['composer'],
        'requirements.txt' => ['pip'],
        'pyproject.toml' => ['pipenv'],
        'Pipfile' => ['pipenv'],
        'DESCRIPTION' => ['r'],
        'Gemfile' => ['bundler'],
        'Cargo.toml' => ['cargo'],
        'build.sbt' => ['sbt'],
        'Package.swift' => ['spm'],
        'meson.build' => ['meson']
    ];

    /**
     * Auto-labels pull requests based on dependency file changes.
     *
     * Processes the PR diff to find modified dependency files,
     * and applies relevant labels, always including 'dependencies'.
     *
     * @param array $metadata Metadata for the pull request including token and URLs.
     *
     * @return void
     */
    public static function autoLabel($metadata)
    {
        // Get the pull request diff
        $url = $metadata["pullRequestUrl"] . ".diff";
        $diffResponse = doRequestGitHub($metadata["token"], $url, null, "GET");
        $diff = $diffResponse->getBody();

        $lines = explode("\n", $diff);
        $changedFiles = [];
        foreach ($lines as $line) {
            if (strpos($line, '+++ b/') === 0) {
                $filePath = substr($line, 6);
                $changedFiles[] = $filePath;
            }
        }

        $labelsToAdd = [];
        foreach ($changedFiles as $file) {
            $basename = basename($file);
            if (isset(self::$dependencyMapping[$basename])) {
                foreach (self::$dependencyMapping[$basename] as $label) {
                    if (!in_array($label, $labelsToAdd)) {
                        $labelsToAdd[] = $label;
                    }
                }
            }
        }

        if (empty($labelsToAdd)) {
            return;
        }

        // Always add general dependencies label
        if (!in_array('dependencies', $labelsToAdd)) {
            array_unshift($labelsToAdd, 'dependencies');
        }

        $body = array("labels" => $labelsToAdd);
        doRequestGitHub($metadata["token"], $metadata["labelsUrl"], $body, "POST");
    }
}
