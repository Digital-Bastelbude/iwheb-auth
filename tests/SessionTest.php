<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class SessionTest extends TestCase {
    private string $tmpFile;

    protected function setUp(): void {
        \Database::resetInstance();
        $this->tmpFile = sys_get_temp_dir() . '/php_rest_session_test_' . bin2hex(random_bytes(6)) . '.db';
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

    public function testCreateSessionForExistingUser(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        // Create a user first
        $user = $db->createUser('testtoken123');
        
        // Create session
        $session = $db->createSession('testtoken123');

        $this->assertArrayHasKey('session_id', $session);
        $this->assertArrayHasKey('user_token', $session);
        $this->assertArrayHasKey('code', $session);
        $this->assertArrayHasKey('code_valid_until', $session);
        $this->assertArrayHasKey('expires_at', $session);
        $this->assertArrayHasKey('session_duration', $session);
        $this->assertArrayHasKey('created_at', $session);
        $this->assertArrayHasKey('validated', $session);
        
        $this->assertSame('testtoken123', $session['user_token']);
        $this->assertSame(1800, $session['session_duration']); // Default 30 minutes
        $this->assertFalse($session['validated']); // Initially not validated
        
        // Code should be 6 digits
        $this->assertMatchesRegularExpression('/^\d{6}$/', $session['code']);
        
        // Session ID should be 32 chars, lowercase alphanumeric
        $this->assertSame(32, strlen($session['session_id']));
        $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $session['session_id']);
        
        // Verify timestamps are valid ISO 8601
        $this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $session['expires_at']));
        $this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $session['code_valid_until']));
        $this->assertNotFalse(\DateTime::createFromFormat(\DateTime::ATOM, $session['created_at']));
    }

    public function testCreateSessionWithCustomDuration(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        $beforeCreate = time();
        $session = $db->createSession('testtoken123', 3600); // 1 hour
        $afterCreate = time();
        
        $this->assertSame(3600, $session['session_duration']);
        
        // Parse the expires_at timestamp
        $expiresAt = \DateTime::createFromFormat(\DateTime::ATOM, $session['expires_at']);
        $expiresAtTimestamp = $expiresAt->getTimestamp();
        
        // Should be roughly 3600 seconds from now
        $this->assertGreaterThanOrEqual($beforeCreate + 3600, $expiresAtTimestamp);
        $this->assertLessThanOrEqual($afterCreate + 3600, $expiresAtTimestamp);
    }

    public function testCreateSessionForNonexistentUserThrowsException(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $this->expectException(\StorageException::class);
        $this->expectExceptionMessage('User not found');
        $db->createSession('nonexistent');
    }

    public function testGetSessionBySessionIdReturnsValidSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $originalSession = $db->createSession('testtoken123');
        
        $retrievedSession = $db->getSessionBySessionId($originalSession['session_id']);
        
        $this->assertNotNull($retrievedSession);
        $this->assertSame($originalSession['session_id'], $retrievedSession['session_id']);
        $this->assertSame($originalSession['user_token'], $retrievedSession['user_token']);
        $this->assertSame($originalSession['code'], $retrievedSession['code']);
        $this->assertSame($originalSession['expires_at'], $retrievedSession['expires_at']);
    }

    public function testGetSessionBySessionIdReturnsNullForNonexistent(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $result = $db->getSessionBySessionId('nonexistentsessionid12345678901');
        $this->assertNull($result);
    }

    public function testGetSessionBySessionIdDeletesExpiredSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', -100); // Already expired
        
        $result = $db->getSessionBySessionId($session['session_id']);
        
        $this->assertNull($result); // Should return null for expired session
        
        // Verify session was deleted
        $result2 = $db->getSessionBySessionId($session['session_id']);
        $this->assertNull($result2);
    }

    public function testGetUserBySessionIdReturnsUserData(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $user = $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123');
        
        $retrievedUser = $db->getUserBySessionId($session['session_id']);
        
        $this->assertNotNull($retrievedUser);
        $this->assertSame($user['token'], $retrievedUser['token']);
        // User no longer has code - that's in the session
        $this->assertArrayNotHasKey('code', $retrievedUser);
    }

    public function testGetUserBySessionIdReturnsNullForInvalidSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $result = $db->getUserBySessionId('invalidsessionid123456789012');
        $this->assertNull($result);
    }

    public function testDeleteSessionRemovesSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123');
        
        $deleted = $db->deleteSession($session['session_id']);
        
        $this->assertTrue($deleted);
        
        // Verify session is gone
        $result = $db->getSessionBySessionId($session['session_id']);
        $this->assertNull($result);
    }

    public function testDeleteSessionReturnsFalseForNonexistent(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $deleted = $db->deleteSession('nonexistentsession123456789012');
        $this->assertFalse($deleted);
    }

    public function testDeleteUserSessionsRemovesAllUserSessions(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $db->createUser('testtoken456');
        
        // Create multiple sessions for first user
        $session1 = $db->createSession('testtoken123');
        $session2 = $db->createSession('testtoken123');
        $session3 = $db->createSession('testtoken456'); // Different user
        
        $deletedCount = $db->deleteUserSessions('testtoken123');
        
        $this->assertSame(2, $deletedCount);
        
        // Verify testtoken123 sessions are gone
        $this->assertNull($db->getSessionBySessionId($session1['session_id']));
        $this->assertNull($db->getSessionBySessionId($session2['session_id']));
        
        // Verify other user's session still exists
        $this->assertNotNull($db->getSessionBySessionId($session3['session_id']));
    }

    public function testDeleteExpiredSessionsRemovesOnlyExpired(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create expired sessions
        $expiredSession1 = $db->createSession('testtoken123', -100); // Already expired
        $expiredSession2 = $db->createSession('testtoken123', -50);  // Already expired
        $validSession = $db->createSession('testtoken123', 300);     // Valid for 5 minutes
        
        $deletedCount = $db->deleteExpiredSessions();
        
        $this->assertSame(2, $deletedCount);
        
        // Verify expired sessions are gone
        $this->assertNull($db->getSessionBySessionId($expiredSession1['session_id']));
        $this->assertNull($db->getSessionBySessionId($expiredSession2['session_id']));
        
        // Verify valid session still exists
        $this->assertNotNull($db->getSessionBySessionId($validSession['session_id']));
    }

    public function testDeleteExpiredSessionsWithCustomTimestamp(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create sessions with different expiry times
        $session1 = $db->createSession('testtoken123', 100);  // Expires in 100 seconds
        $session2 = $db->createSession('testtoken123', 200);  // Expires in 200 seconds
        $session3 = $db->createSession('testtoken123', 300);  // Expires in 300 seconds
        
        // Delete sessions that expire before 150 seconds from now
        $cutoffTime = gmdate('c', time() + 150);
        $deletedCount = $db->deleteExpiredSessions($cutoffTime);
        
        $this->assertSame(1, $deletedCount); // Only session1 should be deleted
        
        $this->assertNull($db->getSessionBySessionId($session1['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session2['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session3['session_id']));
    }

    public function testTouchUserBySessionUpdatesUserAndRefreshesSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $user = $db->createUser('testtoken123');
        $originalActivity = $user['last_activity_at'];
        $originalSession = $db->createSession('testtoken123');
        $originalSessionId = $originalSession['session_id'];
        
        sleep(1); // Ensure timestamp difference
        
        $newSessionId = $db->touchUser($originalSessionId);
        
        $this->assertNotNull($newSessionId);
        $this->assertNotSame($originalSessionId, $newSessionId);
        
        // Verify user's last_activity_at was updated
        $updatedUser = $db->getUserByToken('testtoken123');
        $this->assertNotSame($originalActivity, $updatedUser['last_activity_at']);
        
        // Verify new timestamp is more recent
        $time1 = \DateTime::createFromFormat(\DateTime::ATOM, $originalActivity);
        $time2 = \DateTime::createFromFormat(\DateTime::ATOM, $updatedUser['last_activity_at']);
        $this->assertGreaterThan($time1->getTimestamp(), $time2->getTimestamp());
        
        // Old session should be deleted
        $this->assertNull($db->getSessionBySessionId($originalSessionId));
        
        // New session should exist
        $this->assertNotNull($db->getSessionBySessionId($newSessionId));
    }

    public function testTouchUserReturnsNullForInvalidSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $result = $db->touchUser('invalidsession123456789012');
        $this->assertNull($result);
    }

    public function testSessionIdIsUrlSafeAndSecure(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Generate multiple sessions to test session ID format
        $sessionIds = [];
        for ($i = 0; $i < 10; $i++) {
            $session = $db->createSession('testtoken123');
            $sessionIds[] = $session['session_id'];
            
            // Each session ID should be exactly 32 characters
            $this->assertSame(32, strlen($session['session_id']));
            
            // Should only contain lowercase letters and digits (URL-safe base32)
            $this->assertMatchesRegularExpression('/^[a-z2-7]+$/', $session['session_id']);
            
            // Clean up for next iteration
            $db->deleteSession($session['session_id']);
        }
        
        // Verify session IDs are unique
        $uniqueIds = array_unique($sessionIds);
        $this->assertSame(count($sessionIds), count($uniqueIds));
    }

    public function testSessionsAreForeignKeyConstrainedToUsers(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123');
        
        // Verify session exists
        $this->assertNotNull($db->getSessionBySessionId($session['session_id']));
        
        // Delete the user
        $db->deleteUser('testtoken123');
        
        // Session should be automatically deleted due to foreign key cascade
        $this->assertNull($db->getSessionBySessionId($session['session_id']));
    }

    public function testMultipleSessionsPerUser(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create multiple sessions for the same user
        $session1 = $db->createSession('testtoken123', 1800);
        $session2 = $db->createSession('testtoken123', 3600);
        $session3 = $db->createSession('testtoken123', 7200);
        
        // All sessions should exist and be different
        $this->assertNotNull($db->getSessionBySessionId($session1['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session2['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session3['session_id']));
        
        $this->assertNotSame($session1['session_id'], $session2['session_id']);
        $this->assertNotSame($session2['session_id'], $session3['session_id']);
        $this->assertNotSame($session1['session_id'], $session3['session_id']);
        
        // All should point to the same user
        $user1 = $db->getUserBySessionId($session1['session_id']);
        $user2 = $db->getUserBySessionId($session2['session_id']);
        $user3 = $db->getUserBySessionId($session3['session_id']);
        
        $this->assertSame($user1['token'], $user2['token']);
        $this->assertSame($user2['token'], $user3['token']);
        $this->assertSame('testtoken123', $user1['token']);
    }

    public function testValidateSessionMarksSessionAsValidated(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123');
        
        // Initially not validated
        $this->assertFalse($session['validated']);
        $this->assertFalse($db->isSessionValidated($session['session_id']));
        
        // Validate session
        $result = $db->validateSession($session['session_id']);
        $this->assertTrue($result);
        
        // Check if now validated
        $this->assertTrue($db->isSessionValidated($session['session_id']));
        
        // Verify via getSessionBySessionId
        $updatedSession = $db->getSessionBySessionId($session['session_id']);
        $this->assertNotNull($updatedSession);
        $this->assertTrue($updatedSession['validated']);
    }

    public function testValidateSessionReturnsFalseForNonexistentSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $result = $db->validateSession('nonexistentsession123456789012');
        $this->assertFalse($result);
    }

    public function testIsSessionValidatedReturnsFalseForNonexistentSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $result = $db->isSessionValidated('nonexistentsession123456789012');
        $this->assertFalse($result);
    }

    public function testValidateCodeReturnsTrueForValidCode(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123');
        
        $isValid = $db->validateCode($session['session_id'], $session['code']);
        $this->assertTrue($isValid);
    }

    public function testValidateCodeReturnsFalseForInvalidCode(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123');
        
        $isValid = $db->validateCode($session['session_id'], '000000');
        $this->assertFalse($isValid);
    }

    public function testValidateCodeReturnsFalseForNonexistentSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $isValid = $db->validateCode('nonexistentsession123456789012', '123456');
        $this->assertFalse($isValid);
    }

    public function testValidateCodeReturnsFalseForExpiredCode(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', 1800, -100); // Session valid, code expired
        
        $isValid = $db->validateCode($session['session_id'], $session['code']);
        $this->assertFalse($isValid);
    }

    public function testRegenerateSessionCodeCreatesNewCode(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123');
        $originalCode = $session['code'];
        
        $newSession = $db->regenerateSessionCode($session['session_id']);
        
        $this->assertNotNull($newSession);
        $this->assertSame($session['session_id'], $newSession['session_id']);
        $this->assertNotSame($originalCode, $newSession['code']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $newSession['code']);
    }

    public function testRegenerateSessionCodeReturnsNullForNonexistentSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $result = $db->regenerateSessionCode('nonexistentsession123456789012');
        $this->assertNull($result);
    }

    public function testRegenerateSessionCodeWithCustomValidity(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123');
        
        $beforeRegen = time();
        $newSession = $db->regenerateSessionCode($session['session_id'], 600); // 10 minutes
        $afterRegen = time();
        
        $this->assertNotNull($newSession);
        
        // Parse the code_valid_until timestamp
        $codeValidUntil = \DateTime::createFromFormat(\DateTime::ATOM, $newSession['code_valid_until']);
        $codeValidTimestamp = $codeValidUntil->getTimestamp();
        
        // Should be roughly 600 seconds from now
        $this->assertGreaterThanOrEqual($beforeRegen + 600, $codeValidTimestamp);
        $this->assertLessThanOrEqual($afterRegen + 600, $codeValidTimestamp);
    }

    public function testCodeIsAlwaysSixDigits(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create multiple sessions and verify all codes are 6 digits
        for ($i = 0; $i < 20; $i++) {
            $session = $db->createSession('testtoken123');
            $this->assertMatchesRegularExpression('/^\d{6}$/', $session['code']);
            $this->assertSame(6, strlen($session['code']));
            $db->deleteSession($session['session_id']);
        }
    }

    public function testGeneratedCodesAreRandom(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        $codes = [];
        $sessionIds = [];
        for ($i = 0; $i < 10; $i++) {
            $session = $db->createSession('testtoken123');
            $codes[] = $session['code'];
            $sessionIds[] = $session['session_id'];
        }
        
        // Check that we have at least some different codes (not all the same)
        $uniqueCodes = array_unique($codes);
        $this->assertGreaterThan(1, count($uniqueCodes));
        
        // Clean up
        foreach ($sessionIds as $sessionId) {
            $db->deleteSession($sessionId);
        }
    }

    public function testCodeWithLeadingZeros(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Generate many codes to likely get one with leading zeros
        $foundLeadingZero = false;
        $sessionIds = [];
        for ($i = 0; $i < 100; $i++) {
            $session = $db->createSession('testtoken123');
            $sessionIds[] = $session['session_id'];
            if (str_starts_with($session['code'], '0')) {
                $foundLeadingZero = true;
                $this->assertSame(6, strlen($session['code']));
                $this->assertMatchesRegularExpression('/^0\d{5}$/', $session['code']);
                break;
            }
        }
        
        // Clean up
        foreach ($sessionIds as $sessionId) {
            $db->deleteSession($sessionId);
        }
        
        // We should have found at least one code with leading zero in 100 attempts
        // (Probability: ~1 - (0.9)^100 ≈ 99.997%)
        $this->assertTrue($foundLeadingZero, 'Should have generated at least one code with leading zero');
    }

    public function testIsSessionActiveReturnsTrueForActiveSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', 300); // 5 minutes
        
        $isActive = $db->isSessionActive($session['session_id']);
        $this->assertTrue($isActive);
    }

    public function testIsSessionActiveReturnsFalseForExpiredSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', -100); // Already expired
        
        $isActive = $db->isSessionActive($session['session_id']);
        $this->assertFalse($isActive);
        
        // Verify session was deleted
        $this->assertNull($db->getSessionBySessionId($session['session_id']));
    }

    public function testIsSessionActiveReturnsFalseForNonexistentSession(): void {
        $db = \Database::getInstance($this->tmpFile);
        
        $isActive = $db->isSessionActive('nonexistentsession123456789012');
        $this->assertFalse($isActive);
    }
}
