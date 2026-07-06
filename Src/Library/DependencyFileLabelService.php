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
     * Pattern matching GitHub Actions workflow and composite action files
     *
     * @var string
     */
    private $gitHubActionsFilePattern = '/^(\.github\/(workflows|actions)\/.+\.ya?ml|action\.ya?ml)$/i';

    /**
     * Pattern matching a `uses: owner/repo[/path]@ref` line within a workflow diff
     *
     * @var string
     */
    private $actionUsesPattern = '/uses:\s*[\'"]?([\w.\-]+(?:\/[\w.\-]+)+)@([\w.\-]+)/';

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
        $removedActionUses = [];
        $addedActionUses = [];

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if (strlen($line) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $line)) {
                continue;
            }

            if (preg_match('/^\+\+\+ b\/(.+)/', $line, $matches)) {
                $this->checkActionVersionBump($currentFile, $removedActionUses, $addedActionUses, $detectedFiles);
                $currentFile = $matches[1];
                $removedActionUses = [];
                $addedActionUses = [];
                $this->checkDependencyFile($currentFile, $detectedFiles);
                continue;
            }

            if ($currentFile !== null && preg_match($this->gitHubActionsFilePattern, $currentFile)) {
                $this->collectActionUse($line, $removedActionUses, $addedActionUses);
            }
        }

        $this->checkActionVersionBump($currentFile, $removedActionUses, $addedActionUses, $detectedFiles);

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
     * Collects `uses: owner/repo@ref` references from a removed or added diff line
     *
     * @param string $line The raw diff line, including its leading +/- marker
     * @param array &$removedActionUses Map of action name => ref found on removed lines
     * @param array &$addedActionUses Map of action name => ref found on added lines
     * @return void
     */
    private function collectActionUse(string $line, array &$removedActionUses, array &$addedActionUses): void
    {
        if (preg_match('/^-(?!--)/', $line) && preg_match($this->actionUsesPattern, $line, $matches)) {
            $removedActionUses[$matches[1]] = $matches[2];
            return;
        }

        if (preg_match('/^\+(?!\+\+)/', $line) && preg_match($this->actionUsesPattern, $line, $matches)) {
            $addedActionUses[$matches[1]] = $matches[2];
        }
    }

    /**
     * Determines whether a workflow file's diff contains a GitHub Actions version bump
     * (i.e. the same action reference with a different version) and, if so, labels it
     *
     * @param string|null $filePath The file being analyzed
     * @param array $removedActionUses Map of action name => ref found on removed lines
     * @param array $addedActionUses Map of action name => ref found on added lines
     * @param array &$detectedFiles Reference to the array of detected files
     * @return void
     */
    private function checkActionVersionBump(
        ?string $filePath,
        array $removedActionUses,
        array $addedActionUses,
        array &$detectedFiles
    ): void {
        if ($filePath === null) {
            return;
        }

        foreach ($addedActionUses as $action => $newVersion) {
            if (isset($removedActionUses[$action]) && $removedActionUses[$action] !== $newVersion) {
                $detectedFiles['github-actions'] = 'GitHub Actions';
                return;
            }
        }
    }
}
