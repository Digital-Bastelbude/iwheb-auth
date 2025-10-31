<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/UserAuth/Auth/KeyGenerator.php';

class KeyGeneratorTest extends TestCase {
    
    public function testGenerateApiKeyDefaultLength(): void {
        $key = generateApiKey();
        
        // Default length should be 32
        $this->assertSame(32, strlen($key));
        
        // Should only contain URL-safe characters
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $key);
    }

    public function testGenerateApiKeyCustomLength(): void {
        $length = 64;
        $key = generateApiKey($length);
        
        $this->assertSame($length, strlen($key));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $key);
    }

    public function testGenerateApiKeyMinimumLength(): void {
        $length = 16;
        $key = generateApiKey($length);
        
        $this->assertSame($length, strlen($key));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $key);
    }

    public function testGenerateApiKeyThrowsExceptionForTooShort(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key length must be at least 16 characters');
        
        generateApiKey(15);
    }

    public function testGenerateApiKeyIsUnique(): void {
        $key1 = generateApiKey();
        $key2 = generateApiKey();
        
        // Two generated keys should be different
        $this->assertNotSame($key1, $key2);
    }

    public function testGenerateApiKeysMultiple(): void {
        $count = 5;
        $keys = generateApiKeys($count);
        
        // Should generate correct number of keys
        $this->assertCount($count, $keys);
        
        // All keys should be unique
        $uniqueKeys = array_unique($keys);
        $this->assertCount($count, $uniqueKeys);
        
        // All keys should have correct format
        foreach ($keys as $key) {
            $this->assertSame(32, strlen($key));
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $key);
        }
    }

    public function testGenerateApiKeysWithCustomLength(): void {
        $count = 3;
        $length = 48;
        $keys = generateApiKeys($count, $length);
        
        $this->assertCount($count, $keys);
        
        foreach ($keys as $key) {
            $this->assertSame($length, strlen($key));
        }
    }

    public function testIsValidApiKeyFormat(): void {
        // Valid keys
        $this->assertTrue(isValidApiKeyFormat('abc123'));
        $this->assertTrue(isValidApiKeyFormat('ABC123'));
        $this->assertTrue(isValidApiKeyFormat('abc-123'));
        $this->assertTrue(isValidApiKeyFormat('abc_123'));
        $this->assertTrue(isValidApiKeyFormat('a1b2c3_d4-e5'));
        $this->assertTrue(isValidApiKeyFormat(generateApiKey()));
        
        // Invalid keys
        $this->assertFalse(isValidApiKeyFormat('abc 123')); // space
        $this->assertFalse(isValidApiKeyFormat('abc+123')); // plus
        $this->assertFalse(isValidApiKeyFormat('abc/123')); // slash
        $this->assertFalse(isValidApiKeyFormat('abc=123')); // equals
        $this->assertFalse(isValidApiKeyFormat('abc@123')); // at
        $this->assertFalse(isValidApiKeyFormat('abc#123')); // hash
        $this->assertFalse(isValidApiKeyFormat('abc.123')); // dot
        $this->assertFalse(isValidApiKeyFormat('')); // empty
    }

    public function testGeneratedKeysAreUrlSafe(): void {
        for ($i = 0; $i < 100; $i++) {
            $key = generateApiKey();
            
            // URL encode and compare - should be identical
            $encoded = urlencode($key);
            $this->assertSame($key, $encoded, "Generated key should not need URL encoding");
        }
    }

    public function testGenerateApiKeysRandomness(): void {
        // Generate many keys and check they're all different
        $keys = generateApiKeys(100);
        
        $uniqueKeys = array_unique($keys);
        $this->assertCount(100, $uniqueKeys, "All 100 keys should be unique");
    }

    public function testGenerateApiKeyCharacterDistribution(): void {
        $key = generateApiKey(1000); // Large key to test distribution
        
        // Should contain letters
        $this->assertMatchesRegularExpression('/[a-zA-Z]/', $key);
        
        // Should contain numbers
        $this->assertMatchesRegularExpression('/[0-9]/', $key);
        
        // May contain - or _ (but not required for every key)
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $key);
    }
}
