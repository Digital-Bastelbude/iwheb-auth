<?php
declare(strict_types=1);

use IwhebAPI\UserAuth\Database\Database;
use IwhebAPI\UserAuth\Exception\Database\StorageException;
use PHPUnit\Framework\TestCase;

class UserRepositoryTest extends TestCase {
    private Database $db;
    private string $dbFile;
    
    protected function setUp(): void {
        $this->dbFile = sys_get_temp_dir() . '/user_repo_test_' . bin2hex(random_bytes(6)) . '.db';
        $this->db = new Database($this->dbFile);
    }
    
    protected function tearDown(): void {
        if (file_exists($this->dbFile)) @unlink($this->dbFile);
        $walFile = $this->dbFile . '-wal';
        $shmFile = $this->dbFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }
    
    public function testCreateUserCreatesNewUser(): void {
        $token = 'test-user-token-123';
        
        $user = $this->db->createUser($token);
        
        $this->assertIsArray($user);
        $this->assertSame($token, $user['token']);
        $this->assertArrayHasKey('last_activity_at', $user);
        $this->assertNotEmpty($user['last_activity_at']);
    }
    
    public function testCreateUserThrowsExceptionForDuplicateToken(): void {
        $token = 'duplicate-token';
        
        $this->db->createUser($token);
        
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('User with this token already exists');
        
        $this->db->createUser($token); // Should throw
    }
    
    public function testCreateUserThrowsExceptionForEmptyToken(): void {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Token cannot be empty');
        
        $this->db->createUser('');
    }
    
    public function testGetUserByTokenReturnsUserData(): void {
        $token = 'get-user-token';
        $created = $this->db->createUser($token);
        
        $retrieved = $this->db->getUserByToken($token);
        
        $this->assertIsArray($retrieved);
        $this->assertSame($token, $retrieved['token']);
        $this->assertSame($created['last_activity_at'], $retrieved['last_activity_at']);
    }
    
    public function testGetUserByTokenReturnsNullForNonexistentUser(): void {
        $retrieved = $this->db->getUserByToken('nonexistent-token');
        
        $this->assertNull($retrieved);
    }
    
    public function testDeleteUserRemovesUser(): void {
        $token = 'delete-user-token';
        $this->db->createUser($token);
        
        $result = $this->db->deleteUser($token);
        
        $this->assertTrue($result);
        
        // Verify user is gone
        $retrieved = $this->db->getUserByToken($token);
        $this->assertNull($retrieved);
    }
    
    public function testDeleteUserReturnsFalseForNonexistentUser(): void {
        $result = $this->db->deleteUser('nonexistent-token');
        
        $this->assertFalse($result);
    }
    
    public function testTouchUserUpdatesLastActivityAt(): void {
        $token = 'touch-user-token';
        $user = $this->db->createUser($token);
        $oldLastActivity = $user['last_activity_at'];
        
        // Create a session for this user to touch via Database facade
        $session = $this->db->createSession($token, 'test-key');
        
        sleep(1); // Ensure timestamp difference
        
        $result = $this->db->touchUser($session['session_id']);
        
        $this->assertNotNull($result);
        $this->assertSame($session['session_id'], $result);
        
        // Verify user timestamp was updated
        $updated = $this->db->getUserByToken($token);
        $this->assertGreaterThan($oldLastActivity, $updated['last_activity_at']);
    }
    
    public function testTouchUserReturnsNullForNonexistentSession(): void {
        $result = $this->db->touchUser('nonexistent-session-id');
        
        $this->assertNull($result);
    }
}
