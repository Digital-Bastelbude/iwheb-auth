<?php
use PHPUnit\Framework\TestCase;
use App\Security\UidEncryptor;

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
        $encryptor1 = new UidEncryptor($this->validKey, '');
        $encryptor2 = new UidEncryptor($this->validKey); // No AAD specified (defaults to '')
        
        $uid = 'test-uid';
        $token = $encryptor1->encrypt($uid);
        
        // Both should work with empty AAD
        $this->assertSame($uid, $encryptor2->decrypt($token));
    }

    public function testLoadKeyFromEnvWithValidFormat(): void {
        $originalKey = UidEncryptor::generateKey();
        $base64Key = base64_encode($originalKey);
        $envValue = 'base64:' . $base64Key;
        
        // Set environment variable
        putenv("TEST_ENCRYPTION_KEY={$envValue}");
        
        try {
            $loadedKey = UidEncryptor::loadKeyFromEnv('TEST_ENCRYPTION_KEY');
            $this->assertSame($originalKey, $loadedKey);
        } finally {
            // Clean up
            putenv('TEST_ENCRYPTION_KEY');
        }
    }

    public function testLoadKeyFromEnvThrowsExceptionForMissingEnvVar(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Env var NONEXISTENT_KEY must start with \'base64:\'.');
        
        UidEncryptor::loadKeyFromEnv('NONEXISTENT_KEY');
    }

    public function testLoadKeyFromEnvThrowsExceptionForWrongPrefix(): void {
        putenv('TEST_ENCRYPTION_KEY=wrongprefix:dGVzdA==');
        
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Env var TEST_ENCRYPTION_KEY must start with \'base64:\'.');
            
            UidEncryptor::loadKeyFromEnv('TEST_ENCRYPTION_KEY');
        } finally {
            putenv('TEST_ENCRYPTION_KEY');
        }
    }

    public function testLoadKeyFromEnvThrowsExceptionForInvalidBase64(): void {
        putenv('TEST_ENCRYPTION_KEY=base64:invalid-base64!!!');
        
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid base64 key in env var TEST_ENCRYPTION_KEY.');
            
            UidEncryptor::loadKeyFromEnv('TEST_ENCRYPTION_KEY');
        } finally {
            putenv('TEST_ENCRYPTION_KEY');
        }
    }

    public function testLoadKeyFromEnvThrowsExceptionForWrongKeyLength(): void {
        $shortKey = base64_encode('too-short');
        putenv("TEST_ENCRYPTION_KEY=base64:{$shortKey}");
        
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid base64 key in env var TEST_ENCRYPTION_KEY.');
            
            UidEncryptor::loadKeyFromEnv('TEST_ENCRYPTION_KEY');
        } finally {
            putenv('TEST_ENCRYPTION_KEY');
        }
    }

    public function testLoadKeyFromEnvUsesDefaultEnvVar(): void {
        $originalKey = UidEncryptor::generateKey();
        $base64Key = base64_encode($originalKey);
        $envValue = 'base64:' . $base64Key;
        
        putenv("ENCRYPTION_KEY={$envValue}");
        
        try {
            $loadedKey = UidEncryptor::loadKeyFromEnv(); // No parameter = uses default
            $this->assertSame($originalKey, $loadedKey);
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
}