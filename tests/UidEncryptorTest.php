<?php
use PHPUnit\Framework\TestCase;
use iwhebAPI\UserAuth\Database\UidEncryptor;

require_once __DIR__ . '/bootstrap.php';

class UidEncryptorTest extends TestCase {
    private string $validKey;
    private string $anotherValidKey;

    protected function setUp(): void {
        $this->validKey = UidEncryptor::generateKey();
        $this->anotherValidKey = UidEncryptor::generateKey();
    }

    public function testConstructorWithValidKey(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $this->assertInstanceOf(UidEncryptor::class, $encryptor);
    }

    public function testConstructorWithValidKeyAndAAD(): void {
        $encryptor = new UidEncryptor($this->validKey, 'test-context');
        $this->assertInstanceOf(UidEncryptor::class, $encryptor);
    }

    public function testConstructorThrowsExceptionForInvalidKeyLength(): void {
        $invalidKey = 'too-short-key';
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key must be 32 bytes (binary).');
        new UidEncryptor($invalidKey);
    }

    public function testConstructorThrowsExceptionForEmptyKey(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key must be 32 bytes (binary).');
        new UidEncryptor('');
    }

    public function testConstructorThrowsExceptionForTooLongKey(): void {
        $tooLongKey = str_repeat('x', 33);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key must be 32 bytes (binary).');
        new UidEncryptor($tooLongKey);
    }

    public function testGenerateKeyReturns32Bytes(): void {
        $key = UidEncryptor::generateKey();
        $this->assertSame(32, strlen($key));
    }

    public function testGenerateKeyReturnsDifferentKeys(): void {
        $key1 = UidEncryptor::generateKey();
        $key2 = UidEncryptor::generateKey();
        $this->assertNotSame($key1, $key2);
    }

    public function testEncryptReturnsNonEmptyString(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $token = $encryptor->encrypt('test-uid');
        
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testEncryptReturnsUrlSafeBase64(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $token = $encryptor->encrypt('test-uid');
        
        // URL-safe base64 should only contain [A-Za-z0-9_-] and no padding
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        $this->assertStringNotContainsString('=', $token); // No padding
        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('/', $token);
    }

    public function testEncryptReturnsDifferentTokensForSameUID(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $uid = 'test-uid';
        
        $token1 = $encryptor->encrypt($uid);
        $token2 = $encryptor->encrypt($uid);
        
        // Should be different due to random nonce
        $this->assertNotSame($token1, $token2);
    }

    public function testDecryptReturnsOriginalUID(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $originalUID = 'test-uid-123';
        
        $token = $encryptor->encrypt($originalUID);
        $decryptedUID = $encryptor->decrypt($token);
        
        $this->assertSame($originalUID, $decryptedUID);
    }

    public function testEncryptDecryptWithEmptyUID(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $originalUID = '';
        
        $token = $encryptor->encrypt($originalUID);
        $decryptedUID = $encryptor->decrypt($token);
        
        $this->assertSame($originalUID, $decryptedUID);
    }

    public function testEncryptDecryptWithBinaryData(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $originalUID = "\x00\x01\x02\xFF\xFE\xFD"; // Binary data
        
        $token = $encryptor->encrypt($originalUID);
        $decryptedUID = $encryptor->decrypt($token);
        
        $this->assertSame($originalUID, $decryptedUID);
    }

    public function testEncryptDecryptWithLongUID(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $originalUID = str_repeat('A', 1000); // Long UID
        
        $token = $encryptor->encrypt($originalUID);
        $decryptedUID = $encryptor->decrypt($token);
        
        $this->assertSame($originalUID, $decryptedUID);
    }

    public function testEncryptDecryptWithUnicodeUID(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $originalUID = 'user-äöü-🚀-测试';
        
        $token = $encryptor->encrypt($originalUID);
        $decryptedUID = $encryptor->decrypt($token);
        
        $this->assertSame($originalUID, $decryptedUID);
    }

    public function testDecryptWithWrongKeyReturnsNull(): void {
        $encryptor1 = new UidEncryptor($this->validKey);
        $encryptor2 = new UidEncryptor($this->anotherValidKey);
        
        $token = $encryptor1->encrypt('test-uid');
        $result = $encryptor2->decrypt($token);
        
        $this->assertNull($result);
    }

    public function testDecryptWithInvalidTokenReturnsNull(): void {
        $encryptor = new UidEncryptor($this->validKey);
        
        $this->assertNull($encryptor->decrypt('invalid-token'));
        $this->assertNull($encryptor->decrypt(''));
        $this->assertNull($encryptor->decrypt('not-base64-url'));
    }

    public function testDecryptWithTamperedTokenReturnsNull(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $token = $encryptor->encrypt('test-uid');
        
        // Tamper with the token
        $tamperedToken = substr($token, 0, -5) . 'XXXXX';
        
        $this->assertNull($encryptor->decrypt($tamperedToken));
    }

    public function testDecryptWithTruncatedTokenReturnsNull(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $token = $encryptor->encrypt('test-uid');
        
        // Truncate the token
        $truncatedToken = substr($token, 0, 10);
        
        $this->assertNull($encryptor->decrypt($truncatedToken));
    }

    public function testAADMustMatchForDecryption(): void {
        $encryptor1 = new UidEncryptor($this->validKey, 'context-a');
        $encryptor2 = new UidEncryptor($this->validKey, 'context-b');
        $encryptor3 = new UidEncryptor($this->validKey, 'context-a'); // Same AAD as encryptor1
        
        $uid = 'test-uid';
        $token = $encryptor1->encrypt($uid);
        
        // Same key, wrong AAD -> should fail
        $this->assertNull($encryptor2->decrypt($token));
        
        // Same key, same AAD -> should succeed
        $this->assertSame($uid, $encryptor3->decrypt($token));
    }

    public function testAADWithEmptyString(): void {
        $encryptor1 = new UidEncryptor($this->validKey);
        $encryptor2 = new UidEncryptor($this->validKey); // No AAD specified (defaults to '')
        
        $uid = 'test-uid';
        $token = $encryptor1->encrypt($uid);
        
        // Both should work with empty AAD
        $this->assertSame($uid, $encryptor2->decrypt($token));
    }

    public function testFromEnvWithValidFormat(): void {
        $originalKey = UidEncryptor::generateKey();
        $base64Key = base64_encode($originalKey);
        $envValue = 'base64:' . $base64Key;
        
        // Set environment variable
        putenv("TEST_ENCRYPTION_KEY={$envValue}");
        
        try {
            $encryptor = UidEncryptor::fromEnv('TEST_ENCRYPTION_KEY');
            $this->assertInstanceOf(UidEncryptor::class, $encryptor);
            
            // Test that it works
            $uid = 'test-user-123';
            $token = $encryptor->encrypt($uid);
            $this->assertSame($uid, $encryptor->decrypt($token));
        } finally {
            // Clean up
            putenv('TEST_ENCRYPTION_KEY');
        }
    }

    public function testFromEnvThrowsExceptionForMissingEnvVar(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Env var NONEXISTENT_KEY must start with \'base64:\'.');
        
        UidEncryptor::fromEnv('NONEXISTENT_KEY');
    }

    public function testFromEnvThrowsExceptionForWrongPrefix(): void {
        putenv('TEST_ENCRYPTION_KEY=wrongprefix:dGVzdA==');
        
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Env var TEST_ENCRYPTION_KEY must start with \'base64:\'.');
            
            UidEncryptor::fromEnv('TEST_ENCRYPTION_KEY');
        } finally {
            putenv('TEST_ENCRYPTION_KEY');
        }
    }

    public function testFromEnvThrowsExceptionForInvalidBase64(): void {
        putenv('TEST_ENCRYPTION_KEY=base64:invalid-base64!!!');
        
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid base64 key in env var TEST_ENCRYPTION_KEY.');
            
            UidEncryptor::fromEnv('TEST_ENCRYPTION_KEY');
        } finally {
            putenv('TEST_ENCRYPTION_KEY');
        }
    }

    public function testFromEnvThrowsExceptionForWrongKeyLength(): void {
        $shortKey = base64_encode('too-short');
        putenv("TEST_ENCRYPTION_KEY=base64:{$shortKey}");
        
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid base64 key in env var TEST_ENCRYPTION_KEY.');
            
            UidEncryptor::fromEnv('TEST_ENCRYPTION_KEY');
        } finally {
            putenv('TEST_ENCRYPTION_KEY');
        }
    }

    public function testFromEnvUsesDefaultEnvVarAndAad(): void {
        $originalKey = UidEncryptor::generateKey();
        $base64Key = base64_encode($originalKey);
        $envValue = 'base64:' . $base64Key;
        
        putenv("ENCRYPTION_KEY={$envValue}");
        
        try {
            $encryptor = UidEncryptor::fromEnv(); // No parameters = uses defaults
            $this->assertInstanceOf(UidEncryptor::class, $encryptor);
            
            // Test that it works with default AAD 'iwheb-auth'
            $uid = 'test-user-456';
            $token = $encryptor->encrypt($uid);
            $this->assertSame($uid, $encryptor->decrypt($token));
        } finally {
            putenv('ENCRYPTION_KEY');
        }
    }

    public function testMultipleEncryptDecryptCycles(): void {
        $encryptor = new UidEncryptor($this->validKey, 'test-realm');
        
        $testUIDs = [
            'simple-uid',
            'user@example.com',
            '12345',
            'üñíçødé-tëst',
            str_repeat('x', 100),
            '',
            "\x00\x01\x02",
        ];
        
        foreach ($testUIDs as $uid) {
            $token = $encryptor->encrypt($uid);
            $decrypted = $encryptor->decrypt($token);
            
            $this->assertSame($uid, $decrypted, "Failed for UID: " . bin2hex($uid));
        }
    }

    public function testTokensAreReasonablyLong(): void {
        $encryptor = new UidEncryptor($this->validKey);
        $token = $encryptor->encrypt('test');
        
        // Token should be reasonably long (nonce + ciphertext + auth tag, base64url encoded)
        // Minimum: 24 bytes nonce + 4 bytes UID + 16 bytes auth tag = 44 bytes raw
        // Base64 encoded: ~59 characters minimum
        $this->assertGreaterThan(50, strlen($token));
    }

    public function testTokensAreConsistentWithSameAAD(): void {
        $aad = 'consistent-context';
        $encryptor1 = new UidEncryptor($this->validKey, $aad);
        $encryptor2 = new UidEncryptor($this->validKey, $aad);
        
        $uid = 'test-uid';
        
        // Different instances with same key/AAD should be able to decrypt each other's tokens
        $token1 = $encryptor1->encrypt($uid);
        $token2 = $encryptor2->encrypt($uid);
        
        $this->assertSame($uid, $encryptor1->decrypt($token2));
        $this->assertSame($uid, $encryptor2->decrypt($token1));
    }

    public function testUniqueTokenGenerationWithUniqueKey(): void {
        $uniqueKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $encryptor = new UidEncryptor($this->validKey, 'test-aad', $uniqueKey);
        
        $uid = 'test-user-123';
        
        // Generate unique tokens (deterministic)
        $token1 = $encryptor->encrypt($uid, true);
        $token2 = $encryptor->encrypt($uid, true);
        
        // Should be identical (same uid + same unique_key = same deterministic nonce)
        $this->assertSame($token1, $token2);
    }

    public function testUniqueTokensAreDifferentForDifferentUIDs(): void {
        $uniqueKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $encryptor = new UidEncryptor($this->validKey, $uniqueKey);
        
        // Different UIDs should produce different unique tokens
        $token1 = $encryptor->encrypt('user-123', true);
        $token2 = $encryptor->encrypt('user-456', true);
        
        $this->assertNotSame($token1, $token2);
    }

    public function testUniqueTokensCanBeDecrypted(): void {
        $uniqueKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $encryptor = new UidEncryptor($this->validKey, $uniqueKey);
        
        $uid = 'test-user-789';
        $token = $encryptor->encrypt($uid, true);
        
        // Should decrypt correctly
        $decrypted = $encryptor->decrypt($token);
        $this->assertSame($uid, $decrypted);
    }

    public function testRandomTokensAreStillDifferentWithUniqueKey(): void {
        $uniqueKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $encryptor = new UidEncryptor($this->validKey, $uniqueKey);
        
        $uid = 'test-user-999';
        
        // Generate random tokens (generateUnique = false, default behavior)
        $token1 = $encryptor->encrypt($uid, false);
        $token2 = $encryptor->encrypt($uid, false);
        
        // Should still be different (random nonces)
        $this->assertNotSame($token1, $token2);
        
        // But both should decrypt correctly
        $this->assertSame($uid, $encryptor->decrypt($token1));
        $this->assertSame($uid, $encryptor->decrypt($token2));
    }

    public function testUniqueTokensWithoutUniqueKeyUsesRandomNonce(): void {
        // No unique_key provided (null)
        $encryptor = new UidEncryptor($this->validKey);
        
        $uid = 'test-user-555';
        
        // Even with generateUnique = true, should use random nonce when unique_key is null
        $token1 = $encryptor->encrypt($uid, true);
        $token2 = $encryptor->encrypt($uid, true);
        
        // Should be different (fallback to random nonce)
        $this->assertNotSame($token1, $token2);
    }

    public function testUniqueTokensAreConsistentAcrossInstances(): void {
        $uniqueKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        
        // Two separate instances with same keys
        $encryptor1 = new UidEncryptor($this->validKey, 'test-aad', $uniqueKey);
        $encryptor2 = new UidEncryptor($this->validKey, 'test-aad', $uniqueKey);

        $uid = 'test-user-consistent';
        
        // Both should generate identical unique tokens
        $token1 = $encryptor1->encrypt($uid, true);
        $token2 = $encryptor2->encrypt($uid, true);
        
        $this->assertSame($token1, $token2);
        
        // Both should decrypt correctly
        $this->assertSame($uid, $encryptor1->decrypt($token2));
        $this->assertSame($uid, $encryptor2->decrypt($token1));
    }

    public function testUniqueTokensWithDifferentUniqueKeys(): void {
        $uniqueKey1 = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $uniqueKey2 = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        
        $encryptor1 = new UidEncryptor($this->validKey, $uniqueKey1);
        $encryptor2 = new UidEncryptor($this->validKey, $uniqueKey2);
        
        $uid = 'test-user-different-keys';
        
        // Different unique_keys should produce different tokens
        $token1 = $encryptor1->encrypt($uid, true);
        $token2 = $encryptor2->encrypt($uid, true);
        
        $this->assertNotSame($token1, $token2);
        
        // But each should still decrypt correctly with its own encryptor
        $this->assertSame($uid, $encryptor1->decrypt($token1));
        $this->assertSame($uid, $encryptor2->decrypt($token2));
    }

    public function testUniqueTokenForIdentification(): void {
        $uniqueKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $encryptor = new UidEncryptor($this->validKey, $uniqueKey, 'user-context');
        
        // Simulate multiple users
        $users = ['alice', 'bob', 'charlie'];
        $tokens = [];
        
        // Generate unique token for each user
        foreach ($users as $user) {
            $tokens[$user] = $encryptor->encrypt($user, true);
        }
        
        // Verify tokens are unique per user
        $this->assertSame(count($users), count(array_unique($tokens)));
        
        // Verify same user always gets same token
        foreach ($users as $user) {
            $regeneratedToken = $encryptor->encrypt($user, true);
            $this->assertSame($tokens[$user], $regeneratedToken);
        }
        
        // Verify all tokens decrypt correctly
        foreach ($users as $user) {
            $decrypted = $encryptor->decrypt($tokens[$user]);
            $this->assertSame($user, $decrypted);
        }
    }
}