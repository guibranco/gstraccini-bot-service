<?php

namespace GuiBranco\GStracciniBot\Library;

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

    public static function autoLabel($metadata)
    {
        // Get the pull request diff
        $diffResponse = self::getPullRequestDiff($metadata);
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

    private function getPullRequestDiff($metadata)
    {
        return doRequestGitHub($metadata["token"], $metadata["pullRequestUrl"] . ".diff", null, "GET");
    }
}
