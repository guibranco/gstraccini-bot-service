<?php

namespace GuiBranco\GStracciniBot\Library;

/**
 * RFC 6238 TOTP (HMAC-SHA1, 30-second step, 6 digits) — no external
 * dependency, since none could be installed in this environment. Compatible
 * with standard authenticator apps (Google Authenticator, Authy, etc.).
 */
class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALGORITHM = "sha1";
    private const BASE32_ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

    public function generateSecret(int $length = 20): string
    {
        return self::base32Encode(random_bytes($length));
    }

    public function getCode(string $base32Secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        return $this->hotpAt($base32Secret, intdiv($timestamp, self::PERIOD));
    }

    /**
     * Verifies a code, tolerating clock drift of `$window` steps (30s each)
     * on either side of the current time.
     */
    public function verifyCode(string $base32Secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }

        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->hotpAt($base32Secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    public function getProvisioningUri(string $base32Secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $query = http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        return "otpauth://totp/{$label}?{$query}";
    }

    /**
     * HOTP (RFC 4226) at a specific counter value. Public so it can be
     * verified directly against the RFC's test vectors.
     */
    public function hotpAt(string $base32Secret, int $counter): string
    {
        $key = self::base32Decode($base32Secret);
        $counterBytes = pack('N*', 0, $counter);
        $hash = hash_hmac(self::ALGORITHM, $counterBytes, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary =
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    public static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(rtrim($base32, '='));

        $bits = '';
        foreach (str_split($base32) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                continue;
            }
            $output .= chr(bindec($byte));
        }

        return $output;
    }
}
