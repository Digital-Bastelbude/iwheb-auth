<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Database\Database;
use IwhebAPI\UserAuth\Exception\Database\StorageException;

require_once __DIR__ . '/bootstrap.php';

class DatabaseTest extends TestCase {
    private string $tmpFile;

    protected function setUp(): void {
        $this->tmpFile = sys_get_temp_dir() . '/php_rest_logging_test_' . bin2hex(random_bytes(6)) . '.db';
        if (file_exists($this->tmpFile)) @unlink($this->tmpFile);
        // Also clean up SQLite auxiliary files
        $walFile = $this->tmpFile . '-wal';
        $shmFile = $this->tmpFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }

    protected function tearDown(): void {
        if (file_exists($this->tmpFile) && is_file($this->tmpFile)) @unlink($this->tmpFile);
        // Also clean up SQLite auxiliary files
        $walFile = $this->tmpFile . '-wal';
        $shmFile = $this->tmpFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }

    public function testLoadWhenFileMissingReturnsDefault(): void {
        if (file_exists($this->tmpFile)) @unlink($this->tmpFile);
        $db = new Database($this->tmpFile);

        // When file is missing, no sessions should exist
        $this->assertNull($db->getSessionBySessionId('anysession'));
    }

    public function testSaveAndLoadRoundtrip(): void {
        $db = new Database($this->tmpFile);

        // Create a session using the public API
        $session = createSessionWithToken($db, 'token123', 'test-key');
        $this->assertArrayHasKey('session_id', $session);
        $sessionId = $session['session_id'];

        // Reset instance to force re-read from file
        $db2 = new Database($this->tmpFile);
        $loaded = $db2->getSessionBySessionId($sessionId);

        $this->assertIsArray($loaded);
        $this->assertSame($sessionId, $loaded['session_id']);
    }

    public function testSaveFailsThrowsStorageException(): void {
        // Use a path that is a directory to force database initialization failure
        $dirPath = sys_get_temp_dir() . '/php_rest_logging_test_dir_' . bin2hex(random_bytes(6));
        mkdir($dirPath, 0775);

        $this->expectException(StorageException::class);

        // Initialize Database with the path that points to a directory
        new Database($dirPath);

        // cleanup
        if (is_dir($dirPath)) @rmdir($dirPath);
    }

    public function testLoadWithCorruptDatabaseThrowsException(): void {
        // create a corrupt database file (not a valid SQLite database)
        file_put_contents($this->tmpFile, "this is not a database file");

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Database initialization failed');
        
        $db = new Database($this->tmpFile);
        // Any operation should trigger the database initialization and throw an exception
        $db->getSessionBySessionId('anysession');
    }

    public function testPersistenceAcrossMultipleOperations(): void {
        $db = new Database($this->tmpFile);

        // Create multiple sessions
        $session1 = createSessionWithToken($db, 'token1', 'key1');
        $session2 = createSessionWithToken($db, 'token2', 'key2');
        
        // Reset and verify
        $db2 = new Database($this->tmpFile);
        
        $this->assertNotNull($db2->getSessionBySessionId($session1['session_id']));
        $this->assertNotNull($db2->getSessionBySessionId($session2['session_id']));
    }

    public function testDatabaseCreatesDirectoryIfNotExists(): void {
        $nestedPath = sys_get_temp_dir() . '/php_test_' . bin2hex(random_bytes(6)) . '/nested/data.db';
        
        $this->assertFalse(file_exists(dirname($nestedPath)));
        
        $db = new Database($nestedPath);
        createSessionWithToken($db, 'token123', 'test-key');
        
        $this->assertTrue(file_exists($nestedPath));
        
        // Cleanup
        unlink($nestedPath);
        $walFile = $nestedPath . '-wal';
        $shmFile = $nestedPath . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
        rmdir(dirname($nestedPath));
        rmdir(dirname(dirname($nestedPath)));
    }
}
