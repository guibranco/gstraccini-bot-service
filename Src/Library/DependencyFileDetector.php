<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Class DependencyFileDetector
 *
 * This class detects changes to dependency files in pull requests and applies appropriate labels.
 *
 * @package GuiBranco\GStracciniBot\Library
 */
class DependencyFileDetector
{
    /**
     * Map of dependency files to their corresponding package manager labels
     *
     * @var array
     */
    private $dependencyFileMap = [
        // C#
        '\.csproj$' => 'nuget',
        
        // C/C++
        'CMakeLists\.txt$' => 'cmake',
        'conanfile\.txt$' => 'conan',
        
        // Crystal
        'shard\.yml$' => 'shards',
        
        // Dart
        'pubspec\.yaml$' => 'pub',
        
        // Elixir
        'mix\.exs$' => 'mix',
        
        // Elm
        'elm\.json$' => 'elm',
        
        // F#
        '\.fsproj$' => 'nuget',
        'paket\.dependencies$' => 'paket',
        
        // Go
        'go\.mod$' => 'go-mod',
        
        // Haskell
        'cabal\.config$' => 'cabal',
        'package\.yaml$' => 'stack',
        
        // Java
        'pom\.xml$' => 'maven',
        'build\.gradle$' => 'gradle',
        
        // JavaScript/TypeScript
        'package\.json$' => 'npm',
        
        // Julia
        'Project\.toml$' => 'julia',
        'Manifest\.toml$' => 'julia',
        
        // Kotlin
        'build\.gradle\.kts$' => 'gradle',
        
        // Lua
        '\.rockspec$' => 'luarocks',
        
        // Objective-C
        'Podfile$' => 'cocoapods',
        
        // Perl
        'Makefile\.PL$' => 'cpan',
        'Build\.PL$' => 'cpan',
        
        // PHP
        'composer\.json$' => 'composer',
        
        // Python
        'requirements\.txt$' => 'pip',
        'pyproject\.toml$' => 'pip',
        'Pipfile$' => 'pipenv',
        
        // R
        'DESCRIPTION$' => 'r',
        
        // Ruby
        'Gemfile$' => 'bundler',
        
        // Rust
        'Cargo\.toml$' => 'cargo',
        
        // Scala
        'build\.sbt$' => 'sbt',
        
        // Swift
        'Package\.swift$' => 'spm',
        
        // Vala
        'meson\.build$' => 'meson'
    ];

    /**
     * Analyzes a pull request diff to detect dependency file changes
     *
     * @param string $diffContent The content of the pull request diff
     * @return array An array of detected package manager labels
     */
    public function detectDependencyChanges(string $diffContent): array
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
                $detectedFiles[] = $currentFile;
            }
        }

        return $this->getLabelsForFiles($detectedFiles);
    }

    /**
     * Gets the appropriate labels for the detected files
     *
     * @param array $files Array of file paths detected in the diff
     * @return array Array of labels to apply
     */
    private function getLabelsForFiles(array $files): array
    {
        $labels = ['dependencies'];
        
        foreach ($files as $file) {
            foreach ($this->dependencyFileMap as $pattern => $label) {
                if (preg_match('/' . $pattern . '/', $file) === 1) {
                    $labels[] = $label;
                }
            }
        }
        
        return array_unique($labels);
    }
}