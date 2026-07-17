<?php

namespace GuiBranco\GStracciniBot\Handlers\Checkup;

use GuiBranco\GStracciniBot\Library\Checkup\CheckupRepository;
use GuiBranco\GStracciniBot\Library\Checkup\GitHubCollectionFetcher;

/**
 * Lists every installation of the GitHub App and makes sure each one has a
 * corresponding `github_installations` row, backfilling any that are missing.
 */
class InstallationsCheckupHandler
{
    private CheckupRepository $repository;
    private GitHubCollectionFetcher $fetcher;
    private bool $dryRun;
    private string $webhooksHandlerVersion;

    public function __construct(
        CheckupRepository $repository,
        GitHubCollectionFetcher $fetcher,
        bool $dryRun,
        string $webhooksHandlerVersion
    ) {
        $this->repository = $repository;
        $this->fetcher = $fetcher;
        $this->dryRun = $dryRun;
        $this->webhooksHandlerVersion = $webhooksHandlerVersion;
    }

    /**
     * @param int|null $onlyInstallationId If set, only this installation is returned/checked.
     *
     * @return array{contexts: array<int, object>, checked: int, backfilled: int}
     */
    public function check(?int $onlyInstallationId): array
    {
        $appToken = generateAppToken();
        $installations = $this->fetcher->fetchAllPages($appToken, "app/installations");

        $contexts = [];
        $checked = 0;
        $backfilled = 0;

        foreach ($installations as $installation) {
            if ($onlyInstallationId !== null && (int) $installation->id !== $onlyInstallationId) {
                continue;
            }

            $checked++;
            echo "Installation: {$installation->account->login} (#{$installation->id})\n";

            if (!$this->repository->existsInstallation((int) $installation->id)) {
                echo "  ⚠️  Missing in github_installations — backfilling\n";
                if (!$this->dryRun) {
                    $this->repository->insertInstallation($installation, $this->webhooksHandlerVersion);
                }
                $backfilled++;
            }

            $contexts[] = (object) [
                "id" => (int) $installation->id,
                "login" => $installation->account->login,
                "suspended" => isset($installation->suspended_at) && $installation->suspended_at !== null,
            ];
        }

        return ["contexts" => $contexts, "checked" => $checked, "backfilled" => $backfilled];
    }
}
