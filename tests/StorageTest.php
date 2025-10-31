<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Database\Database;

require_once __DIR__ . '/bootstrap.php';

class StorageTest extends TestCase {
    private string $tmpFile;

    protected function setUp(): void {
        Database::resetInstance();
        $this->tmpFile = sys_get_temp_dir() . '/php_rest_storage_test_' . bin2hex(random_bytes(6)) . '.db';
        if (file_exists($this->tmpFile)) @unlink($this->tmpFile);
        // Also clean up SQLite auxiliary files
        $walFile = $this->tmpFile . '-wal';
        $shmFile = $this->tmpFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }

    protected function tearDown(): void {
        Database::resetInstance();
        if (file_exists($this->tmpFile) && is_file($this->tmpFile)) @unlink($this->tmpFile);
        // Also clean up SQLite auxiliary files
        $walFile = $this->tmpFile . '-wal';
        $shmFile = $this->tmpFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }

    public function testCreateUserGeneratesTimestamp(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $user = $db->createUser('token123');

        $this->assertArrayHasKey('token', $user);
        $this->assertArrayHasKey('last_activity_at', $user);
        $this->assertSame('token123', $user['token']);
        
        // Verify timestamp is valid ISO 8601
        $this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $user['last_activity_at']));

        // persisted
        Database::resetInstance();
        $db2 = Database::getInstance($this->tmpFile);
        $fetched = $db2->getUserByToken('token123');
        $this->assertSame('token123', $fetched['token']);
    }

    public function testCreateUserWithDuplicateTokenThrowsException(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('token123');
        
        $this->expectException(\StorageException::class);
        $this->expectExceptionMessage('User with this token already exists');
        $db->createUser('token123'); // Should throw
    }

    public function testGetUserByTokenReturnsNullWhenNotFound(): void {
        $db = Database::getInstance($this->tmpFile);
        $this->assertNull($db->getUserByToken('nonexistent'));
    }

    public function testMultipleUsersCanBeCreated(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('token1');
        $db->createUser('token2');
        $db->createUser('token3');

        // Verify all users exist
        $this->assertNotNull($db->getUserByToken('token1'));
        $this->assertNotNull($db->getUserByToken('token2'));
        $this->assertNotNull($db->getUserByToken('token3'));
    }

    public function testGetUserByTokenReturnsNullForNonexistentUser(): void {
        $db = Database::getInstance($this->tmpFile);
        
        // Verify no users exist
        $this->assertNull($db->getUserByToken('nonexistent'));
    }

    public function testDeleteUserRemovesRecord(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('token123');
        $this->assertNotNull($db->getUserByToken('token123'));

        $deleted = $db->deleteUser('token123');
        $this->assertTrue($deleted);
        $this->assertNull($db->getUserByToken('token123'));
    }

    public function testDeleteUserReturnsFalseWhenTokenNotFound(): void {
        $db = Database::getInstance($this->tmpFile);
        $deleted = $db->deleteUser('nonexistent');
        $this->assertFalse($deleted);
    }

    // touchUser tests removed - touchUser now only works with session IDs
    // See SessionTest.php for session-based touchUser tests

    public function testMultipleConcurrentOperations(): void {
        $db = Database::getInstance($this->tmpFile);
        
        // Create multiple users
        for ($i = 1; $i <= 10; $i++) {
            $db->createUser("token{$i}");
        }
        
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
        $db = Database::getInstance($this->tmpFile);
        
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
        $db = Database::getInstance($dirPath);
        $db->createUser('token123');

        if (is_dir($dirPath)) @rmdir($dirPath);
    }

    // Code generation tests removed - codes are now generated for sessions, not users
    // See SessionTest.php for session-based code generation tests
}

