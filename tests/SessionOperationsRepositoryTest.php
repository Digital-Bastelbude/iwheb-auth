<?php
declare(strict_types=1);

use IwhebAPI\UserAuth\Database\Database;
use PHPUnit\Framework\TestCase;

class SessionOperationsRepositoryTest extends TestCase {
    private Database $db;
    private string $dbFile;
    
    protected function setUp(): void {
        $this->dbFile = sys_get_temp_dir() . '/session_ops_repo_test_' . bin2hex(random_bytes(6)) . '.db';
        $this->db = new Database($this->dbFile);
    }
    
    protected function tearDown(): void {
        if (file_exists($this->dbFile)) @unlink($this->dbFile);
        $walFile = $this->dbFile . '-wal';
        $shmFile = $this->dbFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }
    
    public function testTouchSessionExtendsExpiry(): void {
        // User creation removed - using token directly: 'token-touch'
        $session = createSessionWithToken($this->db, 'token-touch', 'test-key', 1800);
        $oldExpiry = $session['expires_at'];
        
        sleep(1); // Ensure time difference
        
        $touched = $this->db->touchSession($session['session_id'], 3600);
        
        $this->assertNotNull($touched);
        $this->assertGreaterThan($oldExpiry, $touched['expires_at']);
        $this->assertSame($session['session_id'], $touched['session_id']);
    }
    
    public function testTouchSessionReturnsNullForNonexistentSession(): void {
        $result = $this->db->touchSession('nonexistent-session-id', 1800);
        
        $this->assertNull($result);
    }
    
    public function testDeleteSessionRemovesSession(): void {
        // User creation removed - using token directly: 'token-delete'
        $session = createSessionWithToken($this->db, 'token-delete', 'test-key');
        
        $result = $this->db->deleteSession($session['session_id']);
        
        $this->assertTrue($result);
        
        // Verify session is gone
        $retrieved = $this->db->getSessionBySessionId($session['session_id']);
        $this->assertNull($retrieved);
    }
    
    public function testDeleteSessionReturnsFalseForNonexistentSession(): void {
        $result = $this->db->deleteSession('nonexistent-id');
        
        $this->assertFalse($result);
    }
    
    public function testDeleteUserSessionsRemovesAllUserSessions(): void {
        // User creation removed - using token directly: 'token-multi'
        $session1 = createSessionWithToken($this->db, 'token-multi', 'key1');
        $session2 = createSessionWithToken($this->db, 'token-multi', 'key2');
        $session3 = createSessionWithToken($this->db, 'token-multi', 'key3');
        
        $deletedCount = $this->db->deleteUserSessions('token-multi');
        
        $this->assertSame(3, $deletedCount);
        
        // Verify all sessions are gone
        $this->assertNull($this->db->getSessionBySessionId($session1['session_id']));
        $this->assertNull($this->db->getSessionBySessionId($session2['session_id']));
        $this->assertNull($this->db->getSessionBySessionId($session3['session_id']));
    }
    
    public function testDeleteUserSessionsReturnsZeroWhenNoSessions(): void {
        $deletedCount = $this->db->deleteUserSessions('nonexistent-token');
        
        $this->assertSame(0, $deletedCount);
    }
    
    public function testCreateSessionWithOldSessionIdReparentsChildren(): void {
        // User creation removed - using token directly: 'token-reparent'
        $oldSession = createSessionWithToken($this->db, 'token-reparent', 'key1');
        $this->db->validateSession($oldSession['session_id']); // Must be validated for delegation
        
        // Create child sessions for old session
        $child1 = $this->db->createDelegatedSession($oldSession['session_id'], 'child-key1');
        $child2 = $this->db->createDelegatedSession($oldSession['session_id'], 'child-key2');
        
        // Create new session replacing old one (should reparent children)
        $newSession = createSessionWithToken($this->db, 'token-reparent', 'key1', 1800, 300, $oldSession['session_id']);
        
        // Verify old session is deleted
        $this->assertNull($this->db->getSessionBySessionId($oldSession['session_id']));
        
        // Verify children still exist and have new parent
        $updatedChild1 = $this->db->getSessionBySessionId($child1['session_id']);
        $updatedChild2 = $this->db->getSessionBySessionId($child2['session_id']);
        
        $this->assertNotNull($updatedChild1);
        $this->assertNotNull($updatedChild2);
        $this->assertSame($newSession['session_id'], $updatedChild1['parent_session_id']);
        $this->assertSame($newSession['session_id'], $updatedChild2['parent_session_id']);
    }
}
