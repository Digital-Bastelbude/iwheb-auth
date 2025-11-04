<?php
declare(strict_types=1);

use IwhebAPI\UserAuth\Database\Database;
use PHPUnit\Framework\TestCase;

class SessionValidationRepositoryTest extends TestCase {
    private Database $db;
    private string $dbFile;
    
    protected function setUp(): void {
        $this->dbFile = sys_get_temp_dir() . '/session_val_repo_test_' . bin2hex(random_bytes(6)) . '.db';
        $this->db = new Database($this->dbFile);
    }
    
    protected function tearDown(): void {
        if (file_exists($this->dbFile)) @unlink($this->dbFile);
        $walFile = $this->dbFile . '-wal';
        $shmFile = $this->dbFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }
    
    public function testValidateSessionMarksSessionAsValidated(): void {
        $user = $this->db->createUser('token-validate');
        $session = $this->db->createSession($user['token'], 'test-key');
        
        $this->assertFalse($session['validated']);
        
        $result = $this->db->validateSession($session['session_id']);
        
        $this->assertTrue($result);
        
        // Verify session is now validated
        $updated = $this->db->getSessionBySessionId($session['session_id']);
        $this->assertTrue((bool)$updated['validated']);
    }
    
    public function testValidateSessionReturnsFalseForNonexistentSession(): void {
        $result = $this->db->validateSession('nonexistent-session-id');
        
        $this->assertFalse($result);
    }
    
    public function testIsSessionValidatedReturnsTrueForValidatedSession(): void {
        $user = $this->db->createUser('token-check-validated');
        $session = $this->db->createSession($user['token'], 'test-key');
        $this->db->validateSession($session['session_id']);
        
        $isValidated = $this->db->isSessionValidated($session['session_id']);
        
        $this->assertTrue($isValidated);
    }
    
    public function testIsSessionValidatedReturnsFalseForUnvalidatedSession(): void {
        $user = $this->db->createUser('token-not-validated');
        $session = $this->db->createSession($user['token'], 'test-key');
        
        $isValidated = $this->db->isSessionValidated($session['session_id']);
        
        $this->assertFalse($isValidated);
    }
    
    public function testIsSessionValidatedReturnsFalseForNonexistentSession(): void {
        $isValidated = $this->db->isSessionValidated('nonexistent-id');
        
        $this->assertFalse($isValidated);
    }
    
    public function testIsSessionActiveReturnsTrueForValidSession(): void {
        $user = $this->db->createUser('token-active');
        $session = $this->db->createSession($user['token'], 'test-key', 3600);
        
        $isActive = $this->db->isSessionActive($session['session_id']);
        
        $this->assertTrue($isActive);
    }
    
    public function testIsSessionActiveReturnsFalseForExpiredSession(): void {
        $user = $this->db->createUser('token-expired');
        $session = $this->db->createSession($user['token'], 'test-key', 1); // 1 second
        
        sleep(2); // Wait for expiration
        
        $isActive = $this->db->isSessionActive($session['session_id']);
        
        $this->assertFalse($isActive);
        
        // Verify session was deleted
        $deleted = $this->db->getSessionBySessionId($session['session_id']);
        $this->assertNull($deleted);
    }
    
    public function testIsSessionActiveReturnsFalseForNonexistentSession(): void {
        $isActive = $this->db->isSessionActive('nonexistent-id');
        
        $this->assertFalse($isActive);
    }
    
    public function testValidateCodeReturnsTrueForValidCode(): void {
        $user = $this->db->createUser('token-code-valid');
        $session = $this->db->createSession($user['token'], 'test-key', 1800, 300);
        
        $isValid = $this->db->validateCode($session['session_id'], $session['code']);
        
        $this->assertTrue($isValid);
    }
    
    public function testValidateCodeReturnsFalseForInvalidCode(): void {
        $user = $this->db->createUser('token-code-invalid');
        $session = $this->db->createSession($user['token'], 'test-key');
        
        $isValid = $this->db->validateCode($session['session_id'], 'wrong-code');
        
        $this->assertFalse($isValid);
    }
    
    public function testValidateCodeReturnsFalseForExpiredCode(): void {
        $user = $this->db->createUser('token-code-expired');
        $session = $this->db->createSession($user['token'], 'test-key', 1800, 1); // Code valid for 1 second
        
        sleep(2); // Wait for code to expire
        
        $isValid = $this->db->validateCode($session['session_id'], $session['code']);
        
        $this->assertFalse($isValid);
    }
    
    public function testValidateCodeReturnsFalseForNonexistentSession(): void {
        $isValid = $this->db->validateCode('nonexistent-id', 'any-code');
        
        $this->assertFalse($isValid);
    }
    
    public function testRegenerateSessionCodeCreatesNewCode(): void {
        $user = $this->db->createUser('token-regen');
        $session = $this->db->createSession($user['token'], 'test-key');
        $oldCode = $session['code'];
        $oldCodeValidUntil = $session['code_valid_until'];
        
        sleep(1); // Ensure timestamp difference
        
        $regenerated = $this->db->regenerateSessionCode($session['session_id'], 600);
        
        $this->assertNotNull($regenerated);
        $this->assertNotSame($oldCode, $regenerated['code']);
        $this->assertGreaterThan($oldCodeValidUntil, $regenerated['code_valid_until']);
        $this->assertSame($session['session_id'], $regenerated['session_id']);
    }
    
    public function testRegenerateSessionCodeReturnsNullForNonexistentSession(): void {
        $result = $this->db->regenerateSessionCode('nonexistent-id');
        
        $this->assertNull($result);
    }
    
    public function testCheckSessionAccessReturnsTrueForMatchingApiKey(): void {
        $user = $this->db->createUser('token-access');
        $session = $this->db->createSession($user['token'], 'test-key');
        
        $hasAccess = $this->db->checkSessionAccess($session['session_id'], 'test-key');
        
        $this->assertTrue($hasAccess);
    }
    
    public function testCheckSessionAccessReturnsFalseForDifferentApiKey(): void {
        $user = $this->db->createUser('token-no-access');
        $session = $this->db->createSession($user['token'], 'test-key');
        
        $hasAccess = $this->db->checkSessionAccess($session['session_id'], 'different-key');
        
        $this->assertFalse($hasAccess);
    }
    
    public function testCheckSessionAccessReturnsFalseForNonexistentSession(): void {
        $hasAccess = $this->db->checkSessionAccess('nonexistent-id', 'any-key');
        
        $this->assertFalse($hasAccess);
    }
}
