<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\CryptoHelper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CryptoHelperTest extends TestCase
{
    private CryptoHelper $crypto;

    protected function setUp(): void
    {
        $this->crypto = new CryptoHelper(base64_encode(str_repeat("k", 32)));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = "super-secret-api-key-value";
        $encrypted = $this->crypto->encrypt($plaintext);

        $this->assertNotSame($plaintext, $encrypted);
        $this->assertSame($plaintext, $this->crypto->decrypt($encrypted));
    }

    public function testEncryptIsNonDeterministic(): void
    {
        $plaintext = "same-input";
        $this->assertNotSame($this->crypto->encrypt($plaintext), $this->crypto->encrypt($plaintext));
    }

    public function testDecryptRejectsTamperedPayload(): void
    {
        $encrypted = $this->crypto->encrypt("some-value");
        $tampered = substr($encrypted, 0, -4) . "abcd";

        $this->expectException(RuntimeException::class);
        $this->crypto->decrypt($tampered);
    }

    public function testConstructorRejectsInvalidKeyLength(): void
    {
        $this->expectException(RuntimeException::class);
        new CryptoHelper(base64_encode("too-short"));
    }

    public function testConstructorRejectsMissingKey(): void
    {
        $this->expectException(RuntimeException::class);
        new CryptoHelper("");
    }
}
