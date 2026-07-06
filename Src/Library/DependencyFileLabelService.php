<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * Class DependencyFileLabelService
 *
 * This class provides functionality to detect dependency file changes in pull requests
 * and apply appropriate labels based on the detected package managers.
 *
 * @package GuiBranco\GStracciniBot\Library
 */
class DependencyFileLabelService
{
    /**
     * Mapping of dependency files to their corresponding package managers and labels
     *
     * @var array
     */
    private $dependencyFileMap = [
        // C#
        '\.csproj$' => ['package_manager' => 'NuGet', 'label' => 'nuget'],

        // C/C++
        'CMakeLists\.txt$' => ['package_manager' => 'CMake', 'label' => 'cmake'],
        'conanfile\.txt$' => ['package_manager' => 'Conan', 'label' => 'conan'],

        // Crystal
        'shard\.yml$' => ['package_manager' => 'Shards', 'label' => 'shards'],

        // Dart
        'pubspec\.yaml$' => ['package_manager' => 'Pub', 'label' => 'pub'],

        // Elixir
        'mix\.exs$' => ['package_manager' => 'Mix', 'label' => 'mix'],

        // Elm
        'elm\.json$' => ['package_manager' => 'Elm', 'label' => 'elm'],

        // F#
        '\.fsproj$' => ['package_manager' => 'NuGet', 'label' => 'nuget'],
        'paket\.dependencies$' => ['package_manager' => 'Paket', 'label' => 'paket'],

        // Go
        'go\.mod$' => ['package_manager' => 'Go modules', 'label' => 'go-mod'],

        // Haskell
        'cabal\.config$' => ['package_manager' => 'Cabal', 'label' => 'cabal'],
        'package\.yaml$' => ['package_manager' => 'Stack', 'label' => 'stack'],

        // Java
        'pom\.xml$' => ['package_manager' => 'Maven', 'label' => 'maven'],
        'build\.gradle$' => ['package_manager' => 'Gradle', 'label' => 'gradle'],

        // JavaScript/TypeScript
        'package\.json$' => ['package_manager' => 'npm/Yarn', 'label' => 'npm'],

        // Julia
        'Project\.toml$' => ['package_manager' => 'Pkg', 'label' => 'julia'],
        'Manifest\.toml$' => ['package_manager' => 'Pkg', 'label' => 'julia'],

        // Kotlin
        'build\.gradle\.kts$' => ['package_manager' => 'Gradle', 'label' => 'gradle'],

        // Lua
        '\.rockspec$' => ['package_manager' => 'LuaRocks', 'label' => 'luarocks'],

        // Objective-C
        'Podfile$' => ['package_manager' => 'CocoaPods', 'label' => 'cocoapods'],

        // Perl
        'Makefile\.PL$' => ['package_manager' => 'CPAN', 'label' => 'cpan'],
        'Build\.PL$' => ['package_manager' => 'CPAN', 'label' => 'cpan'],

        // PHP
        'composer\.json$' => ['package_manager' => 'Composer', 'label' => 'composer'],

        // Python
        'requirements\.txt$' => ['package_manager' => 'pip', 'label' => 'pip'],
        'pyproject\.toml$' => ['package_manager' => 'pip', 'label' => 'pip'],
        'Pipfile$' => ['package_manager' => 'pipenv', 'label' => 'pipenv'],

        // R
        'DESCRIPTION$' => ['package_manager' => 'R', 'label' => 'r'],

        // Ruby
        'Gemfile$' => ['package_manager' => 'Bundler', 'label' => 'bundler'],

        // Rust
        'Cargo\.toml$' => ['package_manager' => 'Cargo', 'label' => 'cargo'],

        // Scala
        'build\.sbt$' => ['package_manager' => 'sbt', 'label' => 'sbt'],

        // Swift
        'Package\.swift$' => ['package_manager' => 'Swift Package Manager', 'label' => 'spm'],

        // Vala
        'meson\.build$' => ['package_manager' => 'Meson', 'label' => 'meson']
    ];

    /**
     * Detectors that flag a dependency bump based on diff *content* rather than just the
     * file path. Each entry matches a file, extracts a "name => version" reference from
     * both removed and added lines, and flags a bump when the same name's version changed.
     *
     * @var array
     */
    private $contentBasedDetectors = [
        'github-actions' => [
            'package_manager' => 'GitHub Actions',
            // .github/workflows/*.yml|yaml, .github/actions/**/*.yml|yaml, or a root action.yml|yaml
            'filePattern' => '/^(\.github\/(workflows|actions)\/.+\.ya?ml|action\.ya?ml)$/i',
            // uses: owner/repo[/path]@ref
            'linePattern' => '/uses:\s*[\'"]?([\w.\-]+(?:\/[\w.\-]+)+)@([\w.\-]+)/',
        ],
        'docker' => [
            'package_manager' => 'Docker',
            // Dockerfile, Dockerfile.<suffix>, <prefix>.dockerfile, docker-compose*.yml|yaml, compose*.yml|yaml
            'filePattern' => '/(^|\/)(Dockerfile(\..+)?|[\w.\-]+\.dockerfile|(docker-)?compose(\..+)?\.ya?ml)$/i',
            // FROM image:tag  or  image: image:tag
            'linePattern' => '/(?:FROM\s+|image:\s*)[\'"]?([\w.\-]+(?:\/[\w.\-]+)*):([\w.\-]+)/i',
        ],
    ];

    /**
     * Analyzes a pull request diff to detect dependency file changes
     *
     * @param string $diffContent The pull request diff content
     * @return array An array of detected package managers and their labels
     */
    public function detectDependencyChanges(string $diffContent): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $diffContent));
        $detectedFiles = [];
        $currentFile = null;
        $removedByDetector = [];
        $addedByDetector = [];

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if (strlen($line) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $line)) {
                continue;
            }

            if (preg_match('/^\+\+\+ b\/(.+)/', $line, $matches)) {
                $this->checkContentVersionBumps($removedByDetector, $addedByDetector, $detectedFiles);
                $currentFile = $matches[1];
                $removedByDetector = [];
                $addedByDetector = [];
                $this->checkDependencyFile($currentFile, $detectedFiles);
                continue;
            }

            if ($currentFile !== null) {
                $this->collectContentReference($currentFile, $line, $removedByDetector, $addedByDetector);
            }
        }

        $this->checkContentVersionBumps($removedByDetector, $addedByDetector, $detectedFiles);

        return $detectedFiles;
    }

    /**
     * Checks if a file is a dependency file and adds it to the detected files array
     *
     * @param string $filePath The file path to check
     * @param array &$detectedFiles Reference to the array of detected files
     * @return void
     */
    private function checkDependencyFile(string $filePath, array &$detectedFiles): void
    {
        foreach ($this->dependencyFileMap as $pattern => $info) {
            if (preg_match('/' . $pattern . '/i', $filePath)) {
                $detectedFiles[$info['label']] = $info['package_manager'];
                break;
            }
        }
    }

    /**
     * Runs each content-based detector's line pattern against a removed or added diff line
     * belonging to a matching file, recording the extracted name => version reference
     *
     * @param string $filePath The file the line belongs to
     * @param string $line The raw diff line, including its leading +/- marker
     * @param array &$removedByDetector Map of detector key => (name => ref) found on removed lines
     * @param array &$addedByDetector Map of detector key => (name => ref) found on added lines
     * @return void
     */
    private function collectContentReference(
        string $filePath,
        string $line,
        array &$removedByDetector,
        array &$addedByDetector
    ): void {
        $isRemoved = (bool) preg_match('/^-(?!--)/', $line);
        $isAdded = !$isRemoved && preg_match('/^\+(?!\+\+)/', $line);

        if (!$isRemoved && !$isAdded) {
            return;
        }

        foreach ($this->contentBasedDetectors as $key => $detector) {
            if (!preg_match($detector['filePattern'], $filePath) || !preg_match($detector['linePattern'], $line, $matches)) {
                continue;
            }

            if ($isRemoved) {
                $removedByDetector[$key][$matches[1]] = $matches[2];
            } else {
                $addedByDetector[$key][$matches[1]] = $matches[2];
            }
        }
    }

    /**
     * Compares each content-based detector's removed vs. added references for the file just
     * finished and, if the same name's version changed, labels the corresponding package manager
     *
     * @param array $removedByDetector Map of detector key => (name => ref) found on removed lines
     * @param array $addedByDetector Map of detector key => (name => ref) found on added lines
     * @param array &$detectedFiles Reference to the array of detected files
     * @return void
     */
    private function checkContentVersionBumps(array $removedByDetector, array $addedByDetector, array &$detectedFiles): void
    {
        foreach ($this->contentBasedDetectors as $key => $detector) {
            $removed = $removedByDetector[$key] ?? [];
            $added = $addedByDetector[$key] ?? [];

            foreach ($added as $name => $newVersion) {
                if (isset($removed[$name]) && $removed[$name] !== $newVersion) {
                    $detectedFiles[$key] = $detector['package_manager'];
                    break;
                }
            }
        }
    }
}
