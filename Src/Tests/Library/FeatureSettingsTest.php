<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\DatabaseException;
use GuiBranco\GStracciniBot\Library\FeatureSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Feature settings whose database access is replaced by in-memory values.
 */
class InMemoryFeatureSettings extends FeatureSettings
{
    /**
     * The error readSettings() throws when the database is down.
     */
    public const DATABASE_ERROR = 'Failed to connect to MySQL';

    /**
     * The repository's value; null inherits the account's.
     *
     * @var integer|null
     */
    public ?int $repositoryValue = null;

    /**
     * The account's value; null when the account has no settings.
     *
     * @var integer|null
     */
    public ?int $accountValue = null;

    /**
     * Whether readSettings() fails as the database would.
     *
     * @var boolean
     */
    public bool $databaseDown = false;

    /**
     * The lookups made, as "feature:accountId:repositoryId".
     *
     * @var string[]
     */
    public array $lookups = [];


    /**
     * Returns the in-memory values.
     *
     * @param string  $feature      The feature looked up.
     * @param integer $accountId    The account looked up.
     * @param integer $repositoryId The repository looked up.
     *
     * @return stdClass|null The values.
     */
    protected function readSettings(string $feature, int $accountId, int $repositoryId): ?stdClass
    {
        $this->lookups[] = "{$feature}:{$accountId}:{$repositoryId}";
        if ($this->databaseDown === true) {
            throw new DatabaseException(self::DATABASE_ERROR);
        }

        $row = new stdClass();
        $row->repositoryValue = $this->repositoryValue;
        $row->accountValue = $this->accountValue;
        return $row;

    }//end readSettings()


}//end class

class FeatureSettingsTest extends TestCase
{
    /**
     * The account the lookups are made for.
     */
    private const ACCOUNT_ID = 1;

    /**
     * The repository the lookups are made for.
     */
    private const REPOSITORY_ID = 2;


    /**
     * Without any setting stored the feature is off: it is opt-in.
     *
     * @return void
     */
    public function testDisabledByDefault(): void
    {
        $settings = new InMemoryFeatureSettings();

        $this->assertFalse($settings->isRequestVersionBumpEnabled(self::ACCOUNT_ID, self::REPOSITORY_ID));
        $this->assertSame(['RequestVersionBump:1:2'], $settings->lookups);

    }//end testDisabledByDefault()


    /**
     * A repository inheriting its account follows the account.
     *
     * @return void
     */
    public function testRepositoryInheritsAccount(): void
    {
        $settings = new InMemoryFeatureSettings();
        $settings->accountValue = 1;
        $this->assertTrue($settings->isRequestVersionBumpEnabled(self::ACCOUNT_ID, self::REPOSITORY_ID));

        $settings->accountValue = 0;
        $this->assertFalse($settings->isRequestVersionBumpEnabled(self::ACCOUNT_ID, self::REPOSITORY_ID));

    }//end testRepositoryInheritsAccount()


    /**
     * A repository can opt in while its account is off, and opt out while its account is on.
     *
     * @return void
     */
    public function testRepositoryOverridesAccount(): void
    {
        $settings = new InMemoryFeatureSettings();
        $settings->accountValue = 0;
        $settings->repositoryValue = 1;
        $this->assertTrue($settings->isRequestVersionBumpEnabled(self::ACCOUNT_ID, self::REPOSITORY_ID));

        $settings->accountValue = 1;
        $settings->repositoryValue = 0;
        $this->assertFalse($settings->isRequestVersionBumpEnabled(self::ACCOUNT_ID, self::REPOSITORY_ID));

        $settings->accountValue = null;
        $settings->repositoryValue = 1;
        $this->assertTrue($settings->isRequestVersionBumpEnabled(self::ACCOUNT_ID, self::REPOSITORY_ID));

    }//end testRepositoryOverridesAccount()


    /**
     * When the settings can't be read, the opt-in feature stays off.
     *
     * @return void
     */
    public function testDisabledWhenDatabaseIsDown(): void
    {
        $settings = new InMemoryFeatureSettings();
        $settings->accountValue = 1;
        $settings->databaseDown = true;

        $this->assertFalse($settings->isRequestVersionBumpEnabled(self::ACCOUNT_ID, self::REPOSITORY_ID));

    }//end testDisabledWhenDatabaseIsDown()


    /**
     * Only the known settings columns can be read.
     *
     * @return void
     */
    public function testRejectsUnknownFeature(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new InMemoryFeatureSettings())->isEnabled('Unknown`; DROP TABLE x; --', self::ACCOUNT_ID, self::REPOSITORY_ID);

    }//end testRejectsUnknownFeature()


    /**
     * The values are combined the same way whatever type the driver returns.
     *
     * @return void
     */
    public function testResolveAcceptsStringValues(): void
    {
        $settings = new FeatureSettings();
        $this->assertTrue($settings->resolve('1', '0'));
        $this->assertFalse($settings->resolve('0', '1'));
        $this->assertTrue($settings->resolve(null, '1'));
        $this->assertFalse($settings->resolve(null, null));

    }//end testResolveAcceptsStringValues()


}//end class
