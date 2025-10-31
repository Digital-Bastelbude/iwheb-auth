<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Database\Database;
use IwhebAPI\UserAuth\Exception\Database\StorageException;

require_once __DIR__ . '/bootstrap.php';

class SessionTest extends TestCase {
    private string $tmpFile;
    private string $testApiKey = 'test-api-key-12345';

    protected function setUp(): void {
        Database::resetInstance();
        $this->tmpFile = sys_get_temp_dir() . '/php_rest_session_test_' . bin2hex(random_bytes(6)) . '.db';
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

    public function testCreateSessionForExistingUser(): void {
        $db = Database::getInstance($this->tmpFile);
        
        // Create a user first
        $user = $db->createUser('testtoken123');
        
        // Create session
        $session = $db->createSession('testtoken123', $this->testApiKey);

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
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        $beforeCreate = time();
        $session = $db->createSession('testtoken123', $this->testApiKey, 3600); // 1 hour
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
        $db = Database::getInstance($this->tmpFile);
        
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('User not found');
        $db->createSession('nonexistent', $this->testApiKey);
    }

    public function testGetSessionBySessionIdReturnsValidSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $originalSession = $db->createSession('testtoken123', $this->testApiKey);
        
        $retrievedSession = $db->getSessionBySessionId($originalSession['session_id']);
        
        $this->assertNotNull($retrievedSession);
        $this->assertSame($originalSession['session_id'], $retrievedSession['session_id']);
        $this->assertSame($originalSession['user_token'], $retrievedSession['user_token']);
        $this->assertSame($originalSession['code'], $retrievedSession['code']);
        $this->assertSame($originalSession['expires_at'], $retrievedSession['expires_at']);
    }

    public function testGetSessionBySessionIdReturnsNullForNonexistent(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $result = $db->getSessionBySessionId('nonexistentsessionid12345678901');
        $this->assertNull($result);
    }

    public function testGetSessionBySessionIdDeletesExpiredSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey, -100); // Already expired
        
        $result = $db->getSessionBySessionId($session['session_id']);
        
        $this->assertNull($result); // Should return null for expired session
        
        // Verify session was deleted
        $result2 = $db->getSessionBySessionId($session['session_id']);
        $this->assertNull($result2);
    }

    public function testGetUserBySessionIdReturnsUserData(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $user = $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        
        $retrievedUser = $db->getUserBySessionId($session['session_id']);
        
        $this->assertNotNull($retrievedUser);
        $this->assertSame($user['token'], $retrievedUser['token']);
        // User no longer has code - that's in the session
        $this->assertArrayNotHasKey('code', $retrievedUser);
    }

    public function testGetUserBySessionIdReturnsNullForInvalidSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $result = $db->getUserBySessionId('invalidsessionid123456789012');
        $this->assertNull($result);
    }

    public function testDeleteSessionRemovesSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        
        $deleted = $db->deleteSession($session['session_id']);
        
        $this->assertTrue($deleted);
        
        // Verify session is gone
        $result = $db->getSessionBySessionId($session['session_id']);
        $this->assertNull($result);
    }

    public function testDeleteSessionReturnsFalseForNonexistent(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $deleted = $db->deleteSession('nonexistentsession123456789012');
        $this->assertFalse($deleted);
    }

    public function testDeleteUserSessionsRemovesAllUserSessions(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $db->createUser('testtoken456');
        
        // Create multiple sessions for first user with different API keys
        // (same API key would auto-delete previous unvalidated session)
        $session1 = $db->createSession('testtoken123', 'key1');
        $session2 = $db->createSession('testtoken123', 'key2');
        $session3 = $db->createSession('testtoken456', $this->testApiKey); // Different user
        
        $deletedCount = $db->deleteUserSessions('testtoken123');
        
        $this->assertSame(2, $deletedCount);
        
        // Verify testtoken123 sessions are gone
        $this->assertNull($db->getSessionBySessionId($session1['session_id']));
        $this->assertNull($db->getSessionBySessionId($session2['session_id']));
        
        // Verify other user's session still exists
        $this->assertNotNull($db->getSessionBySessionId($session3['session_id']));
    }

    public function testDeleteExpiredSessionsRemovesOnlyExpired(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create expired sessions with different API keys to avoid auto-deletion
        $expiredSession1 = $db->createSession('testtoken123', 'key-expired-1', -100); // Already expired
        $expiredSession2 = $db->createSession('testtoken123', 'key-expired-2', -50);  // Already expired
        $validSession = $db->createSession('testtoken123', 'key-valid', 300);     // Valid for 5 minutes
        
        $deletedCount = $db->deleteExpiredSessions();
        
        $this->assertSame(2, $deletedCount);
        
        // Verify expired sessions are gone
        $this->assertNull($db->getSessionBySessionId($expiredSession1['session_id']));
        $this->assertNull($db->getSessionBySessionId($expiredSession2['session_id']));
        
        // Verify valid session still exists
        $this->assertNotNull($db->getSessionBySessionId($validSession['session_id']));
    }

    public function testDeleteExpiredSessionsWithCustomTimestamp(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create sessions with different expiry times and different API keys
        // to avoid auto-deletion of previous unvalidated sessions
        $session1 = $db->createSession('testtoken123', 'key1', 100);  // Expires in 100 seconds
        $session2 = $db->createSession('testtoken123', 'key2', 200);  // Expires in 200 seconds
        $session3 = $db->createSession('testtoken123', 'key3', 300);  // Expires in 300 seconds
        
        // Delete sessions that expire before 150 seconds from now
        $cutoffTime = gmdate('c', time() + 150);
        $deletedCount = $db->deleteExpiredSessions($cutoffTime);
        
        $this->assertSame(1, $deletedCount); // Only session1 should be deleted
        
        $this->assertNull($db->getSessionBySessionId($session1['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session2['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session3['session_id']));
    }

    public function testTouchUserBySessionUpdatesUserAndRefreshesSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $user = $db->createUser('testtoken123');
        $originalActivity = $user['last_activity_at'];
        $originalSession = $db->createSession('testtoken123', $this->testApiKey);
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
        $db = Database::getInstance($this->tmpFile);
        
        $result = $db->touchUser('invalidsession123456789012');
        $this->assertNull($result);
    }

    public function testSessionIdIsUrlSafeAndSecure(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Generate multiple sessions to test session ID format
        $sessionIds = [];
        for ($i = 0; $i < 10; $i++) {
            $session = $db->createSession('testtoken123', $this->testApiKey);
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
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        
        // Verify session exists
        $this->assertNotNull($db->getSessionBySessionId($session['session_id']));
        
        // Delete the user
        $db->deleteUser('testtoken123');
        
        // Session should be automatically deleted due to foreign key cascade
        $this->assertNull($db->getSessionBySessionId($session['session_id']));
    }

    public function testMultipleSessionsPerUser(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create multiple sessions for the same user with same API key
        // Note: Only the last unvalidated session will remain per API key
        $session1 = $db->createSession('testtoken123', $this->testApiKey, 1800);
        $session2 = $db->createSession('testtoken123', $this->testApiKey, 3600);
        $session3 = $db->createSession('testtoken123', $this->testApiKey, 7200);
        
        // Only session3 should exist (previous unvalidated sessions are deleted)
        $this->assertNull($db->getSessionBySessionId($session1['session_id']));
        $this->assertNull($db->getSessionBySessionId($session2['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session3['session_id']));
        
        // But validated sessions should coexist with new unvalidated sessions
        $db->validateSession($session3['session_id']);
        $session4 = $db->createSession('testtoken123', $this->testApiKey, 1800);
        
        // Both validated session3 and new unvalidated session4 should exist
        $this->assertNotNull($db->getSessionBySessionId($session3['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session4['session_id']));
        $this->assertNotSame($session3['session_id'], $session4['session_id']);
        
        // Both should point to the same user
        $user3 = $db->getUserBySessionId($session3['session_id']);
        $user4 = $db->getUserBySessionId($session4['session_id']);
        
        $this->assertSame($user3['token'], $user4['token']);
        $this->assertSame('testtoken123', $user3['token']);
    }

    public function testValidateSessionMarksSessionAsValidated(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        
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
        $db = Database::getInstance($this->tmpFile);
        
        $result = $db->validateSession('nonexistentsession123456789012');
        $this->assertFalse($result);
    }

    public function testIsSessionValidatedReturnsFalseForNonexistentSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $result = $db->isSessionValidated('nonexistentsession123456789012');
        $this->assertFalse($result);
    }

    public function testValidateCodeReturnsTrueForValidCode(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        
        $isValid = $db->validateCode($session['session_id'], $session['code']);
        $this->assertTrue($isValid);
    }

    public function testValidateCodeReturnsFalseForInvalidCode(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        
        $isValid = $db->validateCode($session['session_id'], '000000');
        $this->assertFalse($isValid);
    }

    public function testValidateCodeReturnsFalseForNonexistentSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $isValid = $db->validateCode('nonexistentsession123456789012', '123456');
        $this->assertFalse($isValid);
    }

    public function testValidateCodeReturnsFalseForExpiredCode(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', 1800, -100); // Session valid, code expired
        
        $isValid = $db->validateCode($session['session_id'], $session['code']);
        $this->assertFalse($isValid);
    }

    public function testRegenerateSessionCodeCreatesNewCode(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        $originalCode = $session['code'];
        
        $newSession = $db->regenerateSessionCode($session['session_id']);
        
        $this->assertNotNull($newSession);
        $this->assertSame($session['session_id'], $newSession['session_id']);
        $this->assertNotSame($originalCode, $newSession['code']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $newSession['code']);
    }

    public function testRegenerateSessionCodeReturnsNullForNonexistentSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $result = $db->regenerateSessionCode('nonexistentsession123456789012');
        $this->assertNull($result);
    }

    public function testRegenerateSessionCodeWithCustomValidity(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        
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
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create multiple sessions and verify all codes are 6 digits
        for ($i = 0; $i < 20; $i++) {
            $session = $db->createSession('testtoken123', $this->testApiKey);
            $this->assertMatchesRegularExpression('/^\d{6}$/', $session['code']);
            $this->assertSame(6, strlen($session['code']));
            $db->deleteSession($session['session_id']);
        }
    }

    public function testGeneratedCodesAreRandom(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        $codes = [];
        $sessionIds = [];
        for ($i = 0; $i < 10; $i++) {
            $session = $db->createSession('testtoken123', $this->testApiKey);
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
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Generate many codes to likely get one with leading zeros
        $foundLeadingZero = false;
        $sessionIds = [];
        for ($i = 0; $i < 100; $i++) {
            $session = $db->createSession('testtoken123', $this->testApiKey);
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
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey, 300); // 5 minutes
        
        $isActive = $db->isSessionActive($session['session_id']);
        $this->assertTrue($isActive);
    }

    public function testIsSessionActiveReturnsFalseForExpiredSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey, -100); // Already expired
        
        $isActive = $db->isSessionActive($session['session_id']);
        $this->assertFalse($isActive);
        
        // Verify session was deleted
        $this->assertNull($db->getSessionBySessionId($session['session_id']));
    }

    public function testIsSessionActiveReturnsFalseForNonexistentSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $isActive = $db->isSessionActive('nonexistentsession123456789012');
        $this->assertFalse($isActive);
    }

    // ========== API KEY TESTS ==========

    public function testSessionStoresApiKey(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        
        // Verify api_key is stored in session
        $this->assertArrayHasKey('api_key', $session);
        $this->assertSame($this->testApiKey, $session['api_key']);
        
        // Retrieve session and verify api_key is persisted
        $retrievedSession = $db->getSessionBySessionId($session['session_id']);
        $this->assertNotNull($retrievedSession);
        $this->assertArrayHasKey('api_key', $retrievedSession);
        $this->assertSame($this->testApiKey, $retrievedSession['api_key']);
    }

    public function testCheckSessionAccessWithCorrectApiKey(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        
        // Same API key should have access
        $hasAccess = $db->checkSessionAccess($session['session_id'], $this->testApiKey);
        $this->assertTrue($hasAccess);
    }

    public function testCheckSessionAccessWithWrongApiKey(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $session = $db->createSession('testtoken123', $this->testApiKey);
        
        // Different API key should NOT have access
        $wrongApiKey = 'different-api-key-67890';
        $hasAccess = $db->checkSessionAccess($session['session_id'], $wrongApiKey);
        $this->assertFalse($hasAccess);
    }

    public function testCheckSessionAccessWithNonexistentSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        // Non-existent session should return false
        $hasAccess = $db->checkSessionAccess('nonexistent-session-id', $this->testApiKey);
        $this->assertFalse($hasAccess);
    }

    public function testMultipleSessionsWithDifferentApiKeys(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        $apiKey1 = 'api-key-1';
        $apiKey2 = 'api-key-2';
        $apiKey3 = 'api-key-3';
        
        // Create sessions with different API keys
        $session1 = $db->createSession('testtoken123', $apiKey1);
        $session2 = $db->createSession('testtoken123', $apiKey2);
        $session3 = $db->createSession('testtoken123', $apiKey3);
        
        // Each session should only be accessible with its own API key
        $this->assertTrue($db->checkSessionAccess($session1['session_id'], $apiKey1));
        $this->assertFalse($db->checkSessionAccess($session1['session_id'], $apiKey2));
        $this->assertFalse($db->checkSessionAccess($session1['session_id'], $apiKey3));
        
        $this->assertFalse($db->checkSessionAccess($session2['session_id'], $apiKey1));
        $this->assertTrue($db->checkSessionAccess($session2['session_id'], $apiKey2));
        $this->assertFalse($db->checkSessionAccess($session2['session_id'], $apiKey3));
        
        $this->assertFalse($db->checkSessionAccess($session3['session_id'], $apiKey1));
        $this->assertFalse($db->checkSessionAccess($session3['session_id'], $apiKey2));
        $this->assertTrue($db->checkSessionAccess($session3['session_id'], $apiKey3));
    }

    public function testSessionIsolationBetweenApiKeys(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        $apiKeyA = 'api-key-application-a';
        $apiKeyB = 'api-key-application-b';
        
        // Application A creates a session
        $sessionA = $db->createSession('testtoken123', $apiKeyA);
        
        // Application B creates a different session
        $sessionB = $db->createSession('testtoken123', $apiKeyB);
        
        // Verify sessions are different
        $this->assertNotSame($sessionA['session_id'], $sessionB['session_id']);
        
        // Application A can only access its own session
        $this->assertTrue($db->checkSessionAccess($sessionA['session_id'], $apiKeyA));
        $this->assertFalse($db->checkSessionAccess($sessionB['session_id'], $apiKeyA));
        
        // Application B can only access its own session
        $this->assertTrue($db->checkSessionAccess($sessionB['session_id'], $apiKeyB));
        $this->assertFalse($db->checkSessionAccess($sessionA['session_id'], $apiKeyB));
    }

    public function testCheckSessionAccessWithExpiredSession(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create session that's already expired
        $session = $db->createSession('testtoken123', $this->testApiKey, -100);
        
        // Wait a moment to ensure it's expired
        sleep(1);
        
        // Should return false for expired session
        $hasAccess = $db->checkSessionAccess($session['session_id'], $this->testApiKey);
        $this->assertFalse($hasAccess);
    }

    public function testApiKeyPersistsAfterSessionRotation(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        $originalSession = $db->createSession('testtoken123', $this->testApiKey);
        
        // Validate session (triggers session rotation)
        $db->validateSession($originalSession['session_id']);
        $newSessionId = $db->touchUser($originalSession['session_id']);
        
        // New session should still have the same API key
        $newSession = $db->getSessionBySessionId($newSessionId);
        $this->assertNotNull($newSession);
        $this->assertSame($this->testApiKey, $newSession['api_key']);
        
        // Access check should still work with original API key
        $this->assertTrue($db->checkSessionAccess($newSessionId, $this->testApiKey));
    }

    public function testEmptyApiKeyIsStored(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create session with empty API key (edge case)
        $session = $db->createSession('testtoken123', '');
        
        $this->assertArrayHasKey('api_key', $session);
        $this->assertSame('', $session['api_key']);
        
        // Empty API key should still work for access control
        $this->assertTrue($db->checkSessionAccess($session['session_id'], ''));
        $this->assertFalse($db->checkSessionAccess($session['session_id'], 'any-other-key'));
    }

    public function testCreateSessionDeletesOldUnvalidatedSessions(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create first session (unvalidated) with specific API key
        $session1 = $db->createSession('testtoken123', $this->testApiKey);
        $this->assertFalse($session1['validated']);
        
        // Verify first session exists
        $retrieved1 = $db->getSessionBySessionId($session1['session_id']);
        $this->assertNotNull($retrieved1);
        
        // Create second session for the same user with SAME API key
        // (should delete first unvalidated session)
        $session2 = $db->createSession('testtoken123', $this->testApiKey);
        
        // First session should be deleted
        $retrieved1AfterSecond = $db->getSessionBySessionId($session1['session_id']);
        $this->assertNull($retrieved1AfterSecond);
        
        // Second session should exist
        $retrieved2 = $db->getSessionBySessionId($session2['session_id']);
        $this->assertNotNull($retrieved2);
        $this->assertSame($session2['session_id'], $retrieved2['session_id']);
    }

    public function testCreateSessionOnlyDeletesSameApiKeyUnvalidatedSessions(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create session with first API key (unvalidated)
        $session1 = $db->createSession('testtoken123', 'api-key-1');
        
        // Create session with second API key (should NOT delete session1)
        $session2 = $db->createSession('testtoken123', 'api-key-2');
        
        // Both sessions should exist (different API keys)
        $this->assertNotNull($db->getSessionBySessionId($session1['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session2['session_id']));
        
        // Create another session with first API key (should delete only session1)
        $session3 = $db->createSession('testtoken123', 'api-key-1');
        
        // session1 should be deleted, session2 and session3 should exist
        $this->assertNull($db->getSessionBySessionId($session1['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session2['session_id']));
        $this->assertNotNull($db->getSessionBySessionId($session3['session_id']));
    }

    public function testCreateSessionKeepsValidatedSessions(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create and validate first session
        $session1 = $db->createSession('testtoken123', $this->testApiKey);
        $db->validateSession($session1['session_id']);
        
        // Verify first session is validated
        $this->assertTrue($db->isSessionValidated($session1['session_id']));
        
        // Create second session (should NOT delete validated session)
        $session2 = $db->createSession('testtoken123', $this->testApiKey);
        
        // First session (validated) should still exist
        $retrieved1 = $db->getSessionBySessionId($session1['session_id']);
        $this->assertNotNull($retrieved1);
        $this->assertTrue($retrieved1['validated']);
        
        // Second session should also exist
        $retrieved2 = $db->getSessionBySessionId($session2['session_id']);
        $this->assertNotNull($retrieved2);
        $this->assertFalse($retrieved2['validated']);
    }

    public function testCreateSessionDeletesMultipleUnvalidatedSessions(): void {
        $db = Database::getInstance($this->tmpFile);
        
        $db->createUser('testtoken123');
        
        // Create multiple unvalidated sessions by calling createSession multiple times
        // Each call should delete the previous unvalidated session
        $session1 = $db->createSession('testtoken123', $this->testApiKey);
        $sessionId1 = $session1['session_id'];
        
        // Create second unvalidated session (should delete first)
        $session2 = $db->createSession('testtoken123', $this->testApiKey);
        $sessionId2 = $session2['session_id'];
        
        // First session should be deleted
        $this->assertNull($db->getSessionBySessionId($sessionId1));
        $this->assertNotNull($db->getSessionBySessionId($sessionId2));
        
        // Create third session (should delete second)
        $session3 = $db->createSession('testtoken123', $this->testApiKey);
        
        // Second session should be deleted
        $this->assertNull($db->getSessionBySessionId($sessionId2));
        
        // Only third session should exist
        $this->assertNotNull($db->getSessionBySessionId($session3['session_id']));
    }
}
