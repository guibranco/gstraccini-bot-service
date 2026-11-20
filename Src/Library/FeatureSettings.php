<?php

namespace GuiBranco\GStracciniBot\Library;

use Exception;
use InvalidArgumentException;
use stdClass;

/**
 * Resolves the opt-in features of the bot for a repository.
 *
 * A feature is set per GitHub account (`account_settings`, keyed by the
 * account id) and can be overridden per repository (`repository_settings`,
 * keyed by the repository id, NULL meaning "inherit the account"). The
 * effective value is the repository's when set, else the account's, else
 * disabled: nothing runs until someone opts in. The tables are created by a
 * migration in the webhooks repository.
 */
class FeatureSettings
{
    /**
     * Ask for a version bump decision on feature pull requests without a `+semver:` directive.
     */
    public const REQUEST_VERSION_BUMP = "RequestVersionBump";

    /**
     * The settings columns that can be read; the column name is interpolated, so it is never taken from input.
     */
    public const FEATURES = [self::REQUEST_VERSION_BUMP];


    /**
     * Whether the version bump decision is requested on the repository's pull requests.
     *
     * @param int $accountId    The GitHub id of the account owning the repository.
     * @param int $repositoryId The GitHub id of the repository.
     *
     * @return boolean False when neither the repository nor the account opted in, or when the settings can't be read.
     */
    public function isRequestVersionBumpEnabled(int $accountId, int $repositoryId): bool
    {
        return $this->isEnabled(self::REQUEST_VERSION_BUMP, $accountId, $repositoryId);

    }//end isRequestVersionBumpEnabled()


    /**
     * Resolves a feature for a repository: the repository's value when set, else the account's, else disabled.
     *
     * @param string $feature      One of the FEATURES.
     * @param int    $accountId    The GitHub id of the account owning the repository.
     * @param int    $repositoryId The GitHub id of the repository.
     *
     * @return boolean Whether the feature is enabled; false when the settings can't be read.
     */
    public function isEnabled(string $feature, int $accountId, int $repositoryId): bool
    {
        global $logStream;

        if (in_array($feature, self::FEATURES, true) === false) {
            throw new InvalidArgumentException("Unknown feature setting: {$feature}");
        }

        try {
            $row = $this->readSettings($feature, $accountId, $repositoryId);
        } catch (Exception $exception) {
            // An opt-in feature stays off when its settings can't be read.
            $context = [];
            $context["feature"] = $feature;
            $context["accountId"] = $accountId;
            $context["repositoryId"] = $repositoryId;
            $context["error"] = $exception->getMessage();
            $logStream?->error("Unable to read the {feature} setting of repository #{repositoryId} (account #{accountId}): {error}", $context, "feature_settings");
            return false;
        }

        return self::resolve($row?->repositoryValue ?? null, $row?->accountValue ?? null);

    }//end isEnabled()


    /**
     * Combines the repository and account values of a feature.
     *
     * @param mixed $repositoryValue The repository's value; null inherits the account's.
     * @param mixed $accountValue    The account's value; null when the account has no settings.
     *
     * @return boolean Whether the feature is enabled.
     */
    public static function resolve(mixed $repositoryValue, mixed $accountValue): bool
    {
        if ($repositoryValue !== null) {
            return (int) $repositoryValue === 1;
        }

        if ($accountValue !== null) {
            return (int) $accountValue === 1;
        }

        return false;

    }//end resolve()


    /**
     * Reads the repository and account values of a feature in one query.
     *
     * @param string $feature      One of the FEATURES (a column of both tables).
     * @param int    $accountId    The GitHub id of the account.
     * @param int    $repositoryId The GitHub id of the repository.
     *
     * @return stdClass|null The values (repositoryValue, accountValue), each null when not set.
     *
     * @throws DatabaseException When the database can't be read.
     */
    protected function readSettings(string $feature, int $accountId, int $repositoryId): ?stdClass
    {
        $sql = "SELECT rs.`{$feature}` AS repositoryValue, acs.`{$feature}` AS accountValue
                FROM (SELECT 1 AS one) base
                LEFT JOIN repository_settings rs ON rs.RepositoryId = ?
                LEFT JOIN account_settings acs ON acs.AccountId = ?";

        $mysqli = connectToDatabase();
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ii", $repositoryId, $accountId);
        if ($stmt->execute() === false) {
            $failure = statementFailed($stmt);
            $stmt->close();
            $mysqli->close();
            throw $failure;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_object();
        $result->close();
        $stmt->close();
        $mysqli->close();

        if ($row instanceof stdClass) {
            return $row;
        }

        return null;

    }//end readSettings()


}//end class
