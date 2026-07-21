<?php

namespace GuiBranco\GStracciniBot\Library;

use RuntimeException;

/**
 * AES-256-GCM encryption at rest for secrets stored in the database
 * (integration API keys, TOTP secrets). The master key comes from
 * `secrets/encryption.secrets.php` (global `$encryptionKey`, a base64-encoded
 * 32-byte key) — never hardcoded, never derived from user input.
 */
class CryptoHelper
{
    private const CIPHER = "aes-256-gcm";

    private string $key;

    public function __construct(?string $base64Key = null)
    {
        global $encryptionKey;

        $base64Key = $base64Key ?? $encryptionKey ?? null;
        if (empty($base64Key)) {
            throw new RuntimeException("Encryption key is not configured (secrets/encryption.secrets.php).");
        }

        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException("Encryption key must be a base64-encoded 32-byte value.");
        }

        $this->key = $key;
    }

    /**
     * Encrypts a plaintext value. Returns a single base64 string packing the
     * IV, the auth tag, and the ciphertext, so it can be stored in one column.
     */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = "";

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException("Encryption failed.");
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts a value produced by encrypt().
     */
    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new RuntimeException("Invalid encrypted payload.");
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $tagLength = 16;

        if (strlen($raw) < $ivLength + $tagLength) {
            throw new RuntimeException("Invalid encrypted payload.");
        }

        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, $tagLength);
        $ciphertext = substr($raw, $ivLength + $tagLength);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException("Decryption failed (payload may have been tampered with).");
        }

        return $plaintext;
    }
}
