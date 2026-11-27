<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * What a breaking change pull request still needs to bump the major version,
 * for the CI setups the repository uses (BreakingChangePlanner).
 */
final class BreakingChangePlan
{


    /**
     * Builds a plan.
     *
     * @param boolean     $usesGitVersion     Whether the repository versions with GitVersion.
     * @param boolean     $hasMajorDirective  Whether a commit already carries `+semver: major|breaking`.
     * @param string|null $appVeyorPath       The AppVeyor file on the head branch, null when the repository doesn't use AppVeyor.
     * @param string|null $appVeyorFileSha    The blob sha of the AppVeyor file on the head branch.
     * @param string|null $appVeyorFrom       The AppVeyor version format on the head branch.
     * @param string|null $appVeyorTo         The AppVeyor version format with the next major; null when no bump is needed.
     * @param string|null $appVeyorNewContent The AppVeyor file with the next major; null when no bump is needed.
     *
     * @return void
     */
    public function __construct(
        public readonly bool $usesGitVersion,
        public readonly bool $hasMajorDirective,
        public readonly ?string $appVeyorPath,
        public readonly ?string $appVeyorFileSha,
        public readonly ?string $appVeyorFrom,
        public readonly ?string $appVeyorTo,
        public readonly ?string $appVeyorNewContent
    ) {

    }//end __construct()


    /**
     * Whether a `+semver: major` commit is still needed for GitVersion.
     *
     * @return boolean
     */
    public function needsGitVersionBump(): bool
    {
        return $this->usesGitVersion === true && $this->hasMajorDirective === false;

    }//end needsGitVersionBump()


    /**
     * Whether the major of the AppVeyor version still has to be bumped.
     *
     * @return boolean
     */
    public function needsAppVeyorBump(): bool
    {
        return $this->appVeyorTo !== null && $this->appVeyorNewContent !== null;

    }//end needsAppVeyorBump()


    /**
     * Whether anything is left to do to bump the major version.
     *
     * @return boolean
     */
    public function isNeeded(): bool
    {
        return $this->needsGitVersionBump() === true || $this->needsAppVeyorBump() === true;

    }//end isNeeded()


}//end class
