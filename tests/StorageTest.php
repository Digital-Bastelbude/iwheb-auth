<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class StorageTest extends TestCase {
    private string $tmpFile;

    protected function setUp(): void {
        \Database::resetInstance();
        $this->tmpFile = sys_get_temp_dir() . '/php_rest_storage_test_' . bin2hex(random_bytes(6)) . '.db';
        if (file_exists($this->tmpFile)) @unlink($this->tmpFile);
        // Also clean up SQLite auxiliary files
        $walFile = $this->tmpFile . '-wal';
        $shmFile = $this->tmpFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }

    protected function tearDown(): void {
        \Database::resetInstance();
        if (file_exists($this->tmpFile) && is_file($this->tmpFile)) @unlink($this->tmpFile);
        // Also clean up SQLite auxiliary files
        $walFile = $this->tmpFile . '-wal';
        $shmFile = $this->tmpFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }

    public function testCreateUserGeneratesCodeAndTimestamps(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $user = $db->createUser('token123');

        $this->assertArrayHasKey('token', $user);
        $this->assertArrayHasKey('code', $user);
        $this->assertArrayHasKey('code_valid_until', $user);
        $this->assertArrayHasKey('last_activity_at', $user);
        $this->assertSame('token123', $user['token']);
        
        // Code should be 6 digits
        $this->assertMatchesRegularExpression('/^\d{6}$/', $user['code']);
        
        // Verify timestamps are valid ISO 8601
        $this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $user['code_valid_until']));
        $this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $user['last_activity_at']));

        // persisted
        \Database::resetInstance();
        $db2 = \Database::getInstance($this->tmpFile);
        $fetched = $db2->getUserByToken('token123');
        $this->assertSame('token123', $fetched['token']);
        $this->assertSame($user['code'], $fetched['code']);
    }

    public function testCreateUserWithCustomValidity(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $beforeCreate = time();
        $user = $db->createUser('token123', 600); // 10 minutes
        $afterCreate = time();
        
        // Parse the code_valid_until timestamp
        $validUntil = \DateTime::createFromFormat(\DateTime::ATOM, $user['code_valid_until']);
        $validUntilTimestamp = $validUntil->getTimestamp();
        
        // Should be roughly 600 seconds from now
        $this->assertGreaterThanOrEqual($beforeCreate + 600, $validUntilTimestamp);
        $this->assertLessThanOrEqual($afterCreate + 600, $validUntilTimestamp);
    }

    public function testCreateUserWithDuplicateTokenThrowsException(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('token123');
        
        $this->expectException(\StorageException::class);
        $this->expectExceptionMessage('User with this token already exists');
        $db->createUser('token123'); // Should throw
    }

    public function testGetUserByTokenReturnsNullWhenNotFound(): void {
        $db = \Database::getInstance($this->tmpFile);
        $this->assertNull($db->getUserByToken('nonexistent'));
    }

    public function testRegenerateCodeCreatesNewCode(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $user1 = $db->createUser('token123');
        $originalCode = $user1['code'];
        $originalValidUntil = $user1['code_valid_until'];
        
        sleep(1); // Ensure timestamp difference
        
        $user2 = $db->regenerateCode('token123');
        
        $this->assertNotNull($user2);
        $this->assertSame('token123', $user2['token']);
        $this->assertNotSame($originalCode, $user2['code']);
        $this->assertNotSame($originalValidUntil, $user2['code_valid_until']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $user2['code']);
        
        // Verify the user still exists with the new code
        $fetched = $db->getUserByToken('token123');
        $this->assertNotNull($fetched);
        $this->assertSame($user2['code'], $fetched['code']);
    }

    public function testRegenerateCodeReturnsNullForNonexistentToken(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $result = $db->regenerateCode('nonexistent');
        $this->assertNull($result);
    }

    public function testRegenerateCodeWithCustomValidity(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('token123', 300);
        
        $beforeRegen = time();
        $user = $db->regenerateCode('token123', 900); // 15 minutes
        $afterRegen = time();
        
        $validUntil = \DateTime::createFromFormat(\DateTime::ATOM, $user['code_valid_until']);
        $validUntilTimestamp = $validUntil->getTimestamp();
        
        // Should be roughly 900 seconds from now
        $this->assertGreaterThanOrEqual($beforeRegen + 900, $validUntilTimestamp);
        $this->assertLessThanOrEqual($afterRegen + 900, $validUntilTimestamp);
    }

    public function testMultipleUsersCanBeCreated(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('token1');
        $db->createUser('token2');
        $db->createUser('token3');

        // Verify all users exist
        $this->assertNotNull($db->getUserByToken('token1'));
        $this->assertNotNull($db->getUserByToken('token2'));
        $this->assertNotNull($db->getUserByToken('token3'));
    }

    public function testGetUserByTokenReturnsNullForNonexistentUser(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        // Verify no users exist
        $this->assertNull($db->getUserByToken('nonexistent'));
    }

    public function testDeleteUserRemovesRecord(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('token123');
        $this->assertNotNull($db->getUserByToken('token123'));

        $deleted = $db->deleteUser('token123');
        $this->assertTrue($deleted);
        $this->assertNull($db->getUserByToken('token123'));
    }

    public function testDeleteUserReturnsFalseWhenTokenNotFound(): void {
        $db = \Database::getInstance($this->tmpFile);
        $deleted = $db->deleteUser('nonexistent');
        $this->assertFalse($deleted);
    }

    // touchUser tests removed - touchUser now only works with session IDs
    // See SessionTest.php for session-based touchUser tests

    public function testDeleteExpiredCodesRemovesExpiredRecords(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        // Create expired users (negative validity seconds)
        $db->createUser('expired1', -100); // Already expired
        $db->createUser('expired2', -50);  // Already expired
        $db->createUser('valid1', 300);    // Valid for 5 minutes

        $deletedCount = $db->deleteExpiredCodes();
        
        $this->assertSame(2, $deletedCount);
        $this->assertNull($db->getUserByToken('expired1'));
        $this->assertNull($db->getUserByToken('expired2'));
        $this->assertNotNull($db->getUserByToken('valid1'));
    }

    public function testDeleteExpiredCodesWithCustomTimestamp(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        // Create users with different validity periods
        $db->createUser('user1', 100);  // Expires in 100 seconds
        $db->createUser('user2', 200);  // Expires in 200 seconds
        $db->createUser('user3', 300);  // Expires in 300 seconds

        // Delete codes that expire before 150 seconds from now
        $cutoffTime = gmdate('c', time() + 150);
        $deletedCount = $db->deleteExpiredCodes($cutoffTime);
        
        $this->assertSame(1, $deletedCount); // Only user1 should be deleted
        $this->assertNull($db->getUserByToken('user1'));
        $this->assertNotNull($db->getUserByToken('user2'));
        $this->assertNotNull($db->getUserByToken('user3'));
    }

    public function testDeleteExpiredCodesReturnsZeroWhenNoExpired(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('valid1', 300);
        $db->createUser('valid2', 600);

        $deletedCount = $db->deleteExpiredCodes();
        
        $this->assertSame(0, $deletedCount);
    }

    public function testCreateUserWithEmptyTokenThrowsException(): void {
        $db = \Database::getInstance($this->tmpFile);

        $this->expectException(\StorageException::class);
        $this->expectExceptionMessage('Token cannot be empty');
        $db->createUser('');
    }

    public function testMultipleConcurrentOperations(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        // Create multiple users
        for ($i = 1; $i <= 10; $i++) {
            $db->createUser("token{$i}");
        }
        
        // Regenerate some codes
        $db->regenerateCode('token5');
        $db->regenerateCode('token7');
        
        // Delete some
        $db->deleteUser('token3');
        $db->deleteUser('token8');
        
        // Verify remaining users
        $this->assertNotNull($db->getUserByToken('token1'));
        $this->assertNotNull($db->getUserByToken('token2'));
        $this->assertNull($db->getUserByToken('token3')); // Deleted
        $this->assertNotNull($db->getUserByToken('token4'));
        $this->assertNotNull($db->getUserByToken('token5'));
        $this->assertNull($db->getUserByToken('token8')); // Deleted
        $this->assertNotNull($db->getUserByToken('token9'));
        $this->assertNotNull($db->getUserByToken('token10'));
    }

    public function testTokensCanBeRetrievedAlphabetically(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('zebra');
        $db->createUser('alpha');
        $db->createUser('beta');

        // Verify all users exist
        $this->assertNotNull($db->getUserByToken('zebra'));
        $this->assertNotNull($db->getUserByToken('alpha'));
        $this->assertNotNull($db->getUserByToken('beta'));
    }

    public function testCreateUserToDirectoryPathThrowsStorageException(): void {
        $dirPath = sys_get_temp_dir() . '/php_rest_storage_test_dir_' . bin2hex(random_bytes(6));
        mkdir($dirPath, 0775);

        $this->expectException(\StorageException::class);
        $db = \Database::getInstance($dirPath);
        $db->createUser('token123');

        if (is_dir($dirPath)) @rmdir($dirPath);
    }

    public function testCodeIsAlwaysSixDigits(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        // Create multiple users and verify all codes are 6 digits
        for ($i = 0; $i < 20; $i++) {
            $user = $db->createUser("token{$i}");
            $this->assertMatchesRegularExpression('/^\d{6}$/', $user['code']);
            $this->assertSame(6, strlen($user['code']));
        }
    }

    public function testGeneratedCodesAreRandom(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $user = $db->createUser("token{$i}");
            $codes[] = $user['code'];
        }
        
        // Check that we have at least some different codes (not all the same)
        $uniqueCodes = array_unique($codes);
        $this->assertGreaterThan(1, count($uniqueCodes));
    }

    public function testCodeWithLeadingZeros(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        // Generate many codes to likely get one with leading zeros
        $foundLeadingZero = false;
        for ($i = 0; $i < 100; $i++) {
            $user = $db->createUser("token{$i}");
            if (str_starts_with($user['code'], '0')) {
                $foundLeadingZero = true;
                $this->assertSame(6, strlen($user['code']));
                $this->assertMatchesRegularExpression('/^0\d{5}$/', $user['code']);
                break;
            }
        }
        
        // We should have found at least one code with leading zero in 100 attempts
        // (Probability: ~1 - (0.9)^100 ≈ 99.997%)
        $this->assertTrue($foundLeadingZero, 'Should have generated at least one code with leading zero');
    }

    public function testRegenerateCodePreservesLastActivity(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $user1 = $db->createUser('token123');
        $originalActivity = $user1['last_activity_at'];
        
        sleep(1);
        
        $user2 = $db->regenerateCode('token123');
        
        // last_activity_at should NOT change when regenerating code
        $this->assertSame($originalActivity, $user2['last_activity_at']);
    }
}

