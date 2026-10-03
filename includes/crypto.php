<?php
/**
 * MarketMoke - Cryptography & Secret Management Helper
 * Provides military-grade AES-256-GCM authenticated encryption/decryption
 * and sensitive credential masking for payment gateways and API keys.
 * 
 * Security Features:
 * - Cipher: AES-256-GCM (Authenticated Encryption with Associated Data)
 * - 96-bit (12-byte) Cryptographically Secure Pseudo-Random Nonce/IV per encryption
 * - 128-bit (16-byte) Authentication Tag preventing tampering and bit-flipping
 * - SHA-256 key derivation from MASTER_ENCRYPTION_KEY
 * - Safe credential masking for client-side DOM rendering
 */

require_once __DIR__ . '/../config.php';

if (!defined('CRYPTO_CIPHER')) {
    define('CRYPTO_CIPHER', 'aes-256-gcm');
}

/**
 * Derive a 256-bit (32-byte) binary cryptographic key
 * 
 * @param string|null $customKey Optional override key
 * @return string 32-byte binary key
 */
function getDerivedEncryptionKey(?string $customKey = null): string {
    $rawKey = $customKey ?: (defined('MASTER_ENCRYPTION_KEY') ? MASTER_ENCRYPTION_KEY : (getenv('MASTER_ENCRYPTION_KEY') ?: ''));
    if ($rawKey === '') {
        throw new RuntimeException('MASTER_ENCRYPTION_KEY must be configured before storing or reading encrypted credentials.');
    }
    return hash('sha256', $rawKey, true);
}

/**
 * Encrypt sensitive plaintext string using AES-256-GCM
 * 
 * @param string $plaintext Data to encrypt (API key, private token, wallet secret)
 * @param string|null $customKey Optional key
 * @param string $associatedData Optional authenticated associated data (AAD)
 * @return string Encrypted package in format: "aes256gcm:<base64(iv . tag . ciphertext)>"
 */
function encryptSecret(string $plaintext, ?string $customKey = null, string $associatedData = ''): string {
    if ($plaintext === '') {
        return '';
    }

    $key = getDerivedEncryptionKey($customKey);
    $ivLength = openssl_cipher_iv_length(CRYPTO_CIPHER); // 12 bytes for GCM
    $iv = random_bytes($ivLength);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        CRYPTO_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        $associatedData,
        16 // 16-byte authentication tag
    );

    if ($ciphertext === false) {
        throw new RuntimeException('AES-256-GCM encryption failed.');
    }

    // Binary pack: 12-byte IV + 16-byte Tag + Ciphertext
    $packed = $iv . $tag . $ciphertext;
    return 'aes256gcm:' . base64_encode($packed);
}

/**
 * Decrypt an AES-256-GCM encrypted string
 * 
 * @param string $cipherPayload Encrypted string format "aes256gcm:<base64...>"
 * @param string|null $customKey Optional key
 * @param string $associatedData Optional authenticated associated data (AAD)
 * @return string|null Decrypted plaintext or null if authentication/decryption fails
 */
function decryptSecret(string $cipherPayload, ?string $customKey = null, string $associatedData = ''): ?string {
    if (empty($cipherPayload)) {
        return null;
    }

    // Check if string matches our encrypted prefix
    if (strpos($cipherPayload, 'aes256gcm:') !== 0) {
        // If not encrypted, return null or false to avoid treating plain-text as decrypted
        return null;
    }

    $raw = base64_decode(substr($cipherPayload, 10), true);
    if ($raw === false) {
        return null;
    }

    $ivLength = openssl_cipher_iv_length(CRYPTO_CIPHER); // 12 bytes
    $tagLength = 16; // 16 bytes for GCM

    if (strlen($raw) < ($ivLength + $tagLength)) {
        return null; // Truncated or corrupt payload
    }

    $iv = substr($raw, 0, $ivLength);
    $tag = substr($raw, $ivLength, $tagLength);
    $ciphertext = substr($raw, $ivLength + $tagLength);

    $key = getDerivedEncryptionKey($customKey);

    $plaintext = openssl_decrypt(
        $ciphertext,
        CRYPTO_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        $associatedData
    );

    return $plaintext !== false ? $plaintext : null;
}

/**
 * Mask sensitive credentials for safe client-side DOM rendering.
 * Never exposes raw secret keys or private wallet addresses to users.
 * 
 * Examples:
 * - "pk_live_51M081xYwMarketMoke98124" -> "pk_live_••••••••8124"
 * - "0x71C83921b7f08518bA4982c7a3F51a2d59C34982" -> "0x71••••••••4982"
 * - "paypal-merchant@marketmoke.com" -> "pa••••••••@marketmoke.com"
 * 
 * @param string $secret The plain-text or secret string to mask
 * @param int $prefixVisible Number of leading characters visible
 * @param int $suffixVisible Number of trailing characters visible
 * @param string $maskChar Mask filler representation
 * @return string Masked credential string
 */
function maskSecret(string $secret, int $prefixVisible = 4, int $suffixVisible = 4, string $maskChar = '••••••••'): string {
    $trimmed = trim($secret);
    if ($trimmed === '') {
        return '';
    }

    // Handle email address masking specifically
    if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
        $parts = explode('@', $trimmed, 2);
        $name = $parts[0];
        $domain = $parts[1];
        $visibleStart = substr($name, 0, min(2, strlen($name)));
        return $visibleStart . '••••@' . $domain;
    }

    $len = strlen($trimmed);
    if ($len <= ($prefixVisible + $suffixVisible)) {
        // For very short strings, mask middle or entire
        return substr($trimmed, 0, 1) . '••••' . substr($trimmed, -1);
    }

    $prefix = substr($trimmed, 0, $prefixVisible);
    $suffix = substr($trimmed, -$suffixVisible);

    return $prefix . $maskChar . $suffix;
}

/**
 * Check if an incoming form value is a masked placeholder
 * Used to avoid overwriting existing encrypted secrets when updating forms.
 * 
 * @param string $value The value to check
 * @return bool True if masked, false if fresh user input
 */
function isMaskedValue(string $value): bool {
    $trimmed = trim($value);
    return strpos($trimmed, '••••') !== false || strpos($trimmed, '****') !== false;
}
