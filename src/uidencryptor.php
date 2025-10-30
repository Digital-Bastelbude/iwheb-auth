<?php
declare(strict_types=1);

namespace App\Security;

/**
 * UidEncryptor
 *
 * Minimal, secure AEAD encrypt/decrypt for a UID string using libsodium (XChaCha20-Poly1305).
 * - Encrypts only the UID (no timestamps or extra payload)
 * - URL-safe Base64 tokens (no padding)
 * - Tamper detection via AEAD authentication tag
 *
 * Requirements:
 *   - PHP >= 7.2 with libsodium (sodium_* functions)
 *
 * Usage:
 *   use App\Security\UidEncryptor;
 *
 *   // Load or generate a 32-byte binary key (store securely!)
 *   $key = UidEncryptor::loadKeyFromEnv('ENCRYPTION_KEY'); // expects 'base64:...' in env
 *   $enc = new UidEncryptor($key, 'your-app-context'); // optional AAD
 *
 *   $token = $enc->encrypt('external-user-42');
 *   $uid   = $enc->decrypt($token); // string|null
 */
final class UidEncryptor
{
    /** @var string 32-byte secret key (binary) */
    private $key;

    /** @var string Associated Authenticated Data (AAD) bound to encryption context */
    private $aad;

    /**
     * @param string $key 32-byte binary key (not base64; use generateKey() or loadKeyFromEnv())
     * @param string $aad Optional AAD to bind tokens to a context/realm (must match on decrypt)
     */
    public function __construct(string $key, string $aad = '')
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \InvalidArgumentException('Key must be 32 bytes (binary).');
        }
        $this->key = $key;
        $this->aad = $aad;
    }

    /**
     * Encrypt UID -> URL-safe token.
     *
     * @param string $uid Arbitrary user id string (binary-safe)
     * @return string URL-safe Base64 token
     */
    public function encrypt(string $uid): string
    {
        // Generate a fresh random nonce for each encryption.
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        // AEAD encrypt (ciphertext includes auth tag).
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $uid,
            $this->aad,
            $nonce,
            $this->key
        );

        // Token format: base64url( nonce || ciphertext ) without '=' padding.
        return self::base64urlEncode($nonce . $ciphertext);
    }

    /**
     * Decrypt token -> UID.
     *
     * @param string $token URL-safe Base64 token created by encrypt()
     * @return string|null UID on success, null on tampering/invalid token
     */
    public function decrypt(string $token): ?string
    {
        $raw = self::base64urlDecode($token);
        if ($raw === null) {
            return null;
        }

        $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($raw) < $nonceLen) {
            return null;
        }

        $nonce = substr($raw, 0, $nonceLen);
        $ciphertext = substr($raw, $nonceLen);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $this->aad,
            $nonce,
            $this->key
        );

        // On any failure (wrong key/AAD/manipulated token) libsodium returns false.
        if ($plaintext === false) {
            return null;
        }

        return $plaintext;
    }

    /**
     * Generate a new random 32-byte key (binary). Store it securely and reuse.
     */
    public static function generateKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }

    /**
     * Load a key from an environment variable formatted as 'base64:<...>'.
     * Example .env: ENCRYPTION_KEY=base64:AbCdEf...==
     *
     * @param string $envVar
     * @return string 32-byte binary key
     */
    public static function loadKeyFromEnv(string $envVar = 'ENCRYPTION_KEY'): string
    {
        $val = getenv($envVar);
        if ($val === false || strpos($val, 'base64:') !== 0) {
            throw new \RuntimeException("Env var {$envVar} must start with 'base64:'.");
        }
        $b64 = substr($val, 7);
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \RuntimeException("Invalid base64 key in env var {$envVar}.");
        }
        return $key;
    }

    /**
     * Helper: Base64URL encode without padding.
     */
    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Helper: Base64URL decode with optional padding.
     */
    private static function base64urlDecode(string $data): ?string
    {
        $b64 = strtr($data, '-_', '+/');
        $pad = 4 - (strlen($b64) % 4);
        if ($pad !== 4) {
            $b64 .= str_repeat('=', $pad);
        }
        $decoded = base64_decode($b64, true);
        return $decoded === false ? null : $decoded;
    }
}
