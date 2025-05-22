<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Class DependencyFileAnalyzer
 *
 * This class analyzes pull request diffs to detect changes in dependency files
 * and applies appropriate labels based on the detected changes.
 *
 * @package GuiBranco\GStracciniBot\Library
 */
class DependencyFileAnalyzer
{
    /**
     * Map of dependency files to their corresponding package manager labels
     *
     * @var array
     */
    private $dependencyFileMap = [
        // C#
        '.csproj' => 'nuget',
        // C/C++
        'CMakeLists.txt' => 'cmake',
        'conanfile.txt' => 'conan',
        // Crystal
        'shard.yml' => 'shards',
        // Dart
        'pubspec.yaml' => 'pub',
        // Elixir
        'mix.exs' => 'mix',
        // Elm
        'elm.json' => 'elm',
        // F#
        '.fsproj' => 'nuget',
        'paket.dependencies' => 'paket',
        // Go
        'go.mod' => 'go-mod',
        // Haskell
        'cabal.config' => 'cabal',
        'package.yaml' => 'stack',
        // Java
        'pom.xml' => 'maven',
        'build.gradle' => 'gradle',
        // JavaScript/TypeScript
        'package.json' => 'npm',
        // Julia
        'Project.toml' => 'julia',
        'Manifest.toml' => 'julia',
        // Kotlin
        'build.gradle.kts' => 'gradle',
        // Lua
        '.rockspec' => 'luarocks',
        // Objective-C
        'Podfile' => 'cocoapods',
        // Perl
        'Makefile.PL' => 'cpan',
        'Build.PL' => 'cpan',
        // PHP
        'composer.json' => 'composer',
        // Python
        'requirements.txt' => 'pip',
        'pyproject.toml' => 'pip',
        'Pipfile' => 'pipenv',
        // R
        'DESCRIPTION' => 'r',
        // Ruby
        'Gemfile' => 'bundler',
        // Rust
        'Cargo.toml' => 'cargo',
        // Scala
        'build.sbt' => 'sbt',
        // Swift
        'Package.swift' => 'spm',
        // Vala
        'meson.build' => 'meson'
    ];

    /**
     * Analyzes a pull request diff to detect changes in dependency files
     *
     * @param string $diffContent The content of the pull request diff
     * @return array An array of detected package manager labels
     */
    public function analyzeDiff(string $diffContent): array
    {
        $lines = explode(PHP_EOL, $diffContent);
        $detectedFiles = [];
        $currentFile = null;

        foreach ($lines as $line) {
            // Skip binary files and extremely long lines
            if (strlen($line) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $line) === true) {
                continue;
            }

            // Detect file being modified in the diff
            if (preg_match('/^\+\+\+ b\/(.+)/', $line, $matches) === true) {
                $currentFile = $matches[1];
                $this->checkDependencyFile($currentFile, $detectedFiles);
            }
        }

        return $this->getLabelsFromDetectedFiles($detectedFiles);
    }

    /**
     * Checks if a file is a dependency file and adds it to the detected files array
     *
     * @param string $filePath The path of the file to check
     * @param array &$detectedFiles Reference to the array of detected files
     * @return void
     */
    private function checkDependencyFile(string $filePath, array &$detectedFiles): void
    {
        foreach ($this->dependencyFileMap as $pattern => $label) {
            if (preg_match('/'.preg_quote($pattern, '/').'$/', $filePath) === 1) {
                $detectedFiles[$label] = true;
            }
        }
    }

    /**
     * Gets the labels from the detected files
     *
     * @param array $detectedFiles The array of detected files
     * @return array An array of labels to apply
     */
    private function getLabelsFromDetectedFiles(array $detectedFiles): array
    {
        $labels = array_keys($detectedFiles);
        return empty($labels) ? [] : array_merge(['dependencies'], $labels);
    }
}