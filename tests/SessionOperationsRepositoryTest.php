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
        $user = $this->db->createUser('token-touch');
        $session = $this->db->createSession($user['token'], 'test-key', 1800);
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
        $user = $this->db->createUser('token-delete');
        $session = $this->db->createSession($user['token'], 'test-key');
        
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
        $user = $this->db->createUser('token-multi');
        $session1 = $this->db->createSession($user['token'], 'key1');
        $session2 = $this->db->createSession($user['token'], 'key2');
        $session3 = $this->db->createSession($user['token'], 'key3');
        
        $deletedCount = $this->db->deleteUserSessions($user['token']);
        
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
    
    public function testDeleteExpiredSessionsRemovesOnlyExpiredSessions(): void {
        $user = $this->db->createUser('token-expired');
        
        // Create expired session (0 seconds duration = already expired)
        $expiredSession = $this->db->createSession($user['token'], 'key1', 0);
        
        // Create valid session
        $validSession = $this->db->createSession($user['token'], 'key2', 3600);
        
        sleep(1); // Ensure expired session is in the past
        
        $deletedCount = $this->db->deleteExpiredSessions();
        
        $this->assertSame(1, $deletedCount);
        
        // Verify expired session is gone
        $this->assertNull($this->db->getSessionBySessionId($expiredSession['session_id']));
        
        // Verify valid session still exists
        $this->assertNotNull($this->db->getSessionBySessionId($validSession['session_id']));
    }
    
    public function testDeleteExpiredSessionsWithCustomTimestamp(): void {
        $user = $this->db->createUser('token-custom');
        
        // Create session with 1 second duration (will expire immediately)
        $session = $this->db->createSession($user['token'], 'key1', 1);
        
        sleep(2); // Wait for expiration
        
        // Delete expired sessions
        $deletedCount = $this->db->deleteExpiredSessions();
        
        $this->assertSame(1, $deletedCount);
        $this->assertNull($this->db->getSessionBySessionId($session['session_id']));
    }
    
    public function testCreateSessionWithOldSessionIdReparentsChildren(): void {
        $user = $this->db->createUser('token-reparent');
        $oldSession = $this->db->createSession($user['token'], 'key1');
        $this->db->validateSession($oldSession['session_id']); // Must be validated for delegation
        
        // Create child sessions for old session
        $child1 = $this->db->createDelegatedSession($oldSession['session_id'], 'child-key1');
        $child2 = $this->db->createDelegatedSession($oldSession['session_id'], 'child-key2');
        
        // Create new session replacing old one (should reparent children)
        $newSession = $this->db->createSession($user['token'], 'key1', 1800, 300, $oldSession['session_id']);
        
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
    
    public function testDeleteDuplicateUserApiKeySessionsRemovesDuplicates(): void {
        $user1 = $this->db->createUser('token-dup1');
        $user2 = $this->db->createUser('token-dup2');
        
        // Create multiple sessions for same user+apiKey (duplicates)
        $session1 = $this->db->createSession($user1['token'], 'key1');
        sleep(1); // Ensure different timestamps
        $session2 = $this->db->createSession($user1['token'], 'key1'); // Duplicate!
        
        // Different API key (not duplicate)
        $session3 = $this->db->createSession($user1['token'], 'key2');
        
        // Different user (not duplicate)
        $session4 = $this->db->createSession($user2['token'], 'key1');
        
        // Need to pass UidEncryptor with proper 32-byte key
        $key = sodium_crypto_aead_xchacha20poly1305_ietf_keygen(); // Generates 32-byte key
        $uidEncryptor = new \IwhebAPI\UserAuth\Database\UidEncryptor($key);
        
        $deletedCount = $this->db->deleteDuplicateUserApiKeySessions($uidEncryptor);
        
        // Should delete one session (the older duplicate)
        $this->assertSame(1, $deletedCount);
        
        // Verify newer session still exists
        $this->assertNotNull($this->db->getSessionBySessionId($session2['session_id']));
        
        // Verify other sessions still exist
        $this->assertNotNull($this->db->getSessionBySessionId($session3['session_id']));
        $this->assertNotNull($this->db->getSessionBySessionId($session4['session_id']));
    }
}
