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
        // C/C++ - Using existing labels or fallback to dependencies
        'CMakeLists.txt' => 'dependencies',
        'conanfile.txt' => 'dependencies',
        // Crystal - Using existing labels or fallback to dependencies
        'shard.yml' => 'dependencies',
        // Dart - Using existing labels or fallback to dependencies
        'pubspec.yaml' => 'dependencies',
        // Elixir - Using existing labels or fallback to dependencies
        'mix.exs' => 'dependencies',
        // Elm - Using existing labels or fallback to dependencies
        'elm.json' => 'dependencies',
        // F#
        '.fsproj' => 'nuget', 
        'paket.dependencies' => 'dependencies',
        // Go
        'go.mod' => 'Go Modules',
        // Haskell - Using existing labels or fallback to dependencies
        'cabal.config' => 'dependencies',
        'package.yaml' => 'dependencies',
        // Java
        'pom.xml' => 'Maven',
        'build.gradle' => 'dependencies',
        // JavaScript/TypeScript
        'package.json' => 'NPM',
        // Julia - Using existing labels or fallback to dependencies
        'Project.toml' => 'dependencies',
        'Manifest.toml' => 'dependencies',
        // Kotlin
        'build.gradle.kts' => 'dependencies',
        // Lua - Using existing labels or fallback to dependencies
        '.rockspec' => 'dependencies',
        // Objective-C - Using existing labels or fallback to dependencies
        'Podfile' => 'dependencies',
        // Perl - Using existing labels or fallback to dependencies
        'Makefile.PL' => 'dependencies',
        'Build.PL' => 'dependencies',
        // PHP
        'composer.json' => 'packagist',
        // Python
        'requirements.txt' => 'pip',
        'pyproject.toml' => 'pip',
        'Pipfile' => 'dependencies',
        // R - Using existing labels or fallback to dependencies
        'DESCRIPTION' => 'dependencies',
        // Ruby
        'Gemfile' => 'RubyGems',
        // Rust
        'Cargo.toml' => 'Cargo',
        // Scala - Using existing labels or fallback to dependencies
        'build.sbt' => 'dependencies',
        // Swift - Using existing labels or fallback to dependencies
        'Package.swift' => 'Swift Package Manager',
        // Vala - Using existing labels or fallback to dependencies
        'meson.build' => 'dependencies'
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
