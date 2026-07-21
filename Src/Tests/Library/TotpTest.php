<?php

namespace GuiBranco\GStracciniBot\Tests\Library;

use GuiBranco\GStracciniBot\Library\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    private Totp $totp;

    protected function setUp(): void
    {
        $this->totp = new Totp();
    }

    /**
     * RFC 4226 Appendix D HOTP test vectors for the ASCII secret
     * "12345678901234567890", counters 0-4, 6-digit truncation.
     */
    public function testHotpMatchesRfc4226Vectors(): void
    {
        $secret = Totp::base32Encode("12345678901234567890");

        $expected = [
            0 => "755224",
            1 => "287082",
            2 => "359152",
            3 => "969429",
            4 => "338314",
        ];

        foreach ($expected as $counter => $code) {
            $this->assertSame($code, $this->totp->hotpAt($secret, $counter));
        }
    }

    public function testBase32RoundTrip(): void
    {
        $original = "12345678901234567890";
        $encoded = Totp::base32Encode($original);
        $this->assertSame($original, Totp::base32Decode($encoded));
    }

    public function testGetCodeIsSixDigits(): void
    {
        $secret = $this->totp->generateSecret();
        $code = $this->totp->getCode($secret);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testVerifyCodeAcceptsCurrentCode(): void
    {
        $secret = $this->totp->generateSecret();
        $timestamp = 1700000000;
        $code = $this->totp->getCode($secret, $timestamp);

        $this->assertTrue($this->totp->verifyCode($secret, $code, 1, $timestamp));
    }

    public function testVerifyCodeAcceptsWithinDriftWindow(): void
    {
        $secret = $this->totp->generateSecret();
        $timestamp = 1700000000;
        $codeOneStepAgo = $this->totp->getCode($secret, $timestamp - 30);

        $this->assertTrue($this->totp->verifyCode($secret, $codeOneStepAgo, 1, $timestamp));
    }

    public function testVerifyCodeRejectsOutsideDriftWindow(): void
    {
        $secret = $this->totp->generateSecret();
        $timestamp = 1700000000;
        $codeFarInPast = $this->totp->getCode($secret, $timestamp - 300);

        $this->assertFalse($this->totp->verifyCode($secret, $codeFarInPast, 1, $timestamp));
    }

    public function testVerifyCodeRejectsMalformedInput(): void
    {
        $secret = $this->totp->generateSecret();

        $this->assertFalse($this->totp->verifyCode($secret, "abc123", 1, 1700000000));
        $this->assertFalse($this->totp->verifyCode($secret, "12345", 1, 1700000000));
    }
}
