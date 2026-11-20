<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\AppVeyorCredentials;
use PHPUnit\Framework\TestCase;

/**
 * Tests the AppVeyor API base URL for account-level and user-level keys.
 */
class AppVeyorCredentialsTest extends TestCase
{
    /**
     * The account name given to the credentials.
     */
    private const ACCOUNT = "guibranco";


    /**
     * An account-level key calls the API root.
     *
     * @return void
     */
    public function testAccountLevelKeyUsesApiRoot(): void
    {
        $credentials = new AppVeyorCredentials("abc123def456", self::ACCOUNT);

        $this->assertFalse($credentials->isUserLevelKey());
        $this->assertSame("https://ci.appveyor.com/api/", $credentials->apiBaseUrl());

    }//end testAccountLevelKeyUsesApiRoot()


    /**
     * A user-level key calls the API under the account.
     *
     * @return void
     */
    public function testUserLevelKeyUsesAccountPrefix(): void
    {
        $credentials = new AppVeyorCredentials("v2.abc123def456", self::ACCOUNT);

        $this->assertTrue($credentials->isUserLevelKey());
        $this->assertSame("https://ci.appveyor.com/api/account/guibranco/", $credentials->apiBaseUrl());

    }//end testUserLevelKeyUsesAccountPrefix()


    /**
     * The account name is encoded in the URL.
     *
     * @return void
     */
    public function testAccountNameIsEncoded(): void
    {
        $credentials = new AppVeyorCredentials("v2.abc123def456", "my account");

        $this->assertSame("https://ci.appveyor.com/api/account/my%20account/", $credentials->apiBaseUrl());

    }//end testAccountNameIsEncoded()


}//end class
