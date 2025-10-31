<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Database\Database;
use IwhebAPI\UserAuth\Exception\Database\StorageException;

require_once __DIR__ . '/bootstrap.php';

class DatabaseTest extends TestCase {
    private string $tmpFile;

    protected function setUp(): void {
        Database::resetInstance();
        $this->tmpFile = sys_get_temp_dir() . '/php_rest_logging_test_' . bin2hex(random_bytes(6)) . '.db';
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

    public function testLoadWhenFileMissingReturnsDefault(): void {
        if (file_exists($this->tmpFile)) @unlink($this->tmpFile);
        $db = Database::getInstance($this->tmpFile);

        // When file is missing, no users should exist
        $this->assertNull($db->getUserByToken('anytoken'));
    }

    public function testSaveAndLoadRoundtrip(): void {
        $db = Database::getInstance($this->tmpFile);

        // Create a user using the public API
        $user = $db->createUser('token123');
        $this->assertArrayHasKey('token', $user);
        $token = $user['token'];

        // Reset instance to force re-read from file
        Database::resetInstance();
        $db2 = Database::getInstance($this->tmpFile);
        $loaded = $db2->getUserByToken($token);

        $this->assertIsArray($loaded);
        $this->assertSame($token, $loaded['token']);
    }

    public function testSaveFailsThrowsStorageException(): void {
        // Use a path that is a directory to force database initialization failure
        $dirPath = sys_get_temp_dir() . '/php_rest_logging_test_dir_' . bin2hex(random_bytes(6));
        mkdir($dirPath, 0775);

        $this->expectException(StorageException::class);

        // Initialize Database with the path that points to a directory
        $db = Database::getInstance($dirPath);
        // Attempting to create a user should throw StorageException
        $db->createUser('token123');

        // cleanup
        if (is_dir($dirPath)) @rmdir($dirPath);
    }

    public function testLoadWithCorruptDatabaseThrowsException(): void {
        // create a corrupt database file (not a valid SQLite database)
        file_put_contents($this->tmpFile, "this is not a database file");

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Database initialization failed');
        
        $db = Database::getInstance($this->tmpFile);
        // Any operation should trigger the database initialization and throw an exception
        $db->getUserByToken('anytoken');
    }

    public function testPersistenceAcrossMultipleOperations(): void {
        $db = Database::getInstance($this->tmpFile);

        // Create multiple users
        $db->createUser('token1');
        $db->createUser('token2');
        
        // Reset and verify
        Database::resetInstance();
        $db2 = Database::getInstance($this->tmpFile);
        
        $this->assertNotNull($db2->getUserByToken('token1'));
        $this->assertNotNull($db2->getUserByToken('token2'));
    }

    public function testDatabaseCreatesDirectoryIfNotExists(): void {
        $nestedPath = sys_get_temp_dir() . '/php_test_' . bin2hex(random_bytes(6)) . '/nested/data.db';
        
        $this->assertFalse(file_exists(dirname($nestedPath)));
        
        $db = Database::getInstance($nestedPath);
        $db->createUser('token123');
        
        $this->assertTrue(file_exists($nestedPath));
        
        // Cleanup
        Database::resetInstance();
        unlink($nestedPath);
        $walFile = $nestedPath . '-wal';
        $shmFile = $nestedPath . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
        rmdir(dirname($nestedPath));
        rmdir(dirname(dirname($nestedPath)));
    }
}
