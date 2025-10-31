<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database\Repository;

use IwhebAPI\UserAuth\Exception\Database\StorageException;
use PDO;
use PDOException;

/**
 * SessionRepository
 * 
 * Handles session-related database operations including code validation.
 */
class SessionRepository extends BaseRepository {
    /**
     * Create a new session for a user with auto-generated code.
     *
     * @param string $userToken The user token to create a session for
     * @param string $apiKey The API key used to create this session
     * @param int $sessionDurationSeconds Duration in seconds (default: 1800 = 30 minutes)
     * @param int $codeValiditySeconds Seconds until code expires (default: 300 = 5 minutes)
     * @return array The created session record with code
     * @throws StorageException on database error
     */
    public function createSession(string $userToken, string $apiKey, int $sessionDurationSeconds = 1800, int $codeValiditySeconds = 300): array {
        try {
            // Delete any existing unvalidated sessions for this user with the same API key
            // This ensures only one active login attempt per user per API key
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE user_token = ? AND api_key = ? AND validated = 0');
            $stmt->execute([$userToken, $apiKey]);

            // Generate unique session ID
            do {
                $sessionId = $this->generateSessionId();
                // Check if session ID already exists (very unlikely but better safe)
                $stmt = $this->pdo->prepare('SELECT session_id FROM sessions WHERE session_id = ?');
                $stmt->execute([$sessionId]);
            } while ($stmt->fetch());

            // Generate 6-digit code
            $code = $this->generateCode();

            $expiresAt = $this->getTimestamp($sessionDurationSeconds);
            $codeValidUntil = $this->getTimestamp($codeValiditySeconds);
            $createdAt = $this->getTimestamp();

            // Insert session record with code and api_key
            $stmt = $this->pdo->prepare('INSERT INTO sessions (session_id, user_token, code, code_valid_until, expires_at, session_duration, validated, created_at, api_key) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)');
            $stmt->execute([$sessionId, $userToken, $code, $codeValidUntil, $expiresAt, $sessionDurationSeconds, $createdAt, $apiKey]);

            return [
                'session_id' => $sessionId,
                'user_token' => $userToken,
                'code' => $code,
                'code_valid_until' => $codeValidUntil,
                'expires_at' => $expiresAt,
                'session_duration' => $sessionDurationSeconds,
                'validated' => false,
                'created_at' => $createdAt,
                'api_key' => $apiKey
            ];
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve a session record by session ID. Returns null if not found or expired.
     * For delegated sessions, also validates that the parent session is still active.
     *
     * @param string $sessionId
     * @return array|null Session data or null if not found/expired/parent invalid
     * @throws StorageException on database error
     */
    public function getSessionBySessionId(string $sessionId): ?array {
        try {
            $stmt = $this->pdo->prepare('SELECT session_id, user_token, code, code_valid_until, expires_at, session_duration, validated, created_at, api_key, parent_session_id FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            // Check if session is expired
            $now = $this->getTimestamp();
            if ($row['expires_at'] < $now) {
                // Session expired, delete it and return null
                $this->deleteSession($sessionId);
                return null;
            }

            // If this is a delegated session (has parent_session_id), validate parent is still active
            if ($row['parent_session_id'] !== null) {
                $parentSession = $this->getSessionBySessionId($row['parent_session_id']);
                
                if ($parentSession === null) {
                    // Parent session is invalid/expired, so this delegated session is also invalid
                    $this->deleteSession($sessionId);
                    return null;
                }
            }

            return [
                'session_id' => $row['session_id'],
                'user_token' => $row['user_token'],
                'code' => $row['code'],
                'code_valid_until' => $row['code_valid_until'],
                'expires_at' => $row['expires_at'],
                'session_duration' => (int)$row['session_duration'],
                'validated' => (bool)$row['validated'],
                'created_at' => $row['created_at'],
                'api_key' => $row['api_key'],
                'parent_session_id' => $row['parent_session_id']
            ];
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Refresh a session by extending its expiry time and generating a new session ID.
     *
     * @param string $oldSessionId The current session ID
     * @param string $userToken The user token (for validation)
     * @param string $apiKey The API key (for validation)
     * @return array|null The new session record or null if old session not found
     * @throws StorageException on database error
     */
    public function touchSession(string $oldSessionId, string $userToken, string $apiKey): ?array {
        try {
            // Get existing session
            $session = $this->getSessionBySessionId($oldSessionId);
            if (!$session) {
                return null;
            }

            // Create new session with same duration and API key
            $newSession = $this->createSession($userToken, $apiKey, $session['session_duration']);

            // Copy validation status to new session
            if ($session['validated']) {
                $this->validateSession($newSession['session_id']);
                $newSession['validated'] = true;
            }

            // Delete old session
            $this->deleteSession($oldSessionId);

            return $newSession;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete a session by session ID.
     * Also deletes all child sessions (delegated sessions) to maintain lifecycle binding.
     *
     * @param string $sessionId
     * @return bool True if a session was deleted, false if not found
     * @throws StorageException on database error
     */
    public function deleteSession(string $sessionId): bool {
        try {
            // First, delete all child sessions (delegated sessions bound to this parent)
            $this->deleteChildSessions($sessionId);
            
            // Then delete the session itself
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete all child sessions (delegated sessions) of a parent session recursively.
     *
     * @param string $parentSessionId
     * @return int Number of deleted child sessions
     * @throws StorageException on database error
     */
    private function deleteChildSessions(string $parentSessionId): int {
        try {
            // Get all child sessions
            $stmt = $this->pdo->prepare('SELECT session_id FROM sessions WHERE parent_session_id = ?');
            $stmt->execute([$parentSessionId]);
            $childSessions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $totalDeleted = 0;
            
            // Recursively delete each child session (which may have its own children)
            foreach ($childSessions as $childSessionId) {
                $this->deleteSession($childSessionId);
                $totalDeleted++;
            }
            
            return $totalDeleted;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete all sessions for a specific user.
     *
     * @param string $userToken
     * @return int Number of deleted sessions
     * @throws StorageException on database error
     */
    public function deleteUserSessions(string $userToken): int {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE user_token = ?');
            $stmt->execute([$userToken]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete all expired sessions.
     *
     * @param string|null $beforeTimestamp ISO 8601 timestamp (defaults to now if null)
     * @return int Number of deleted sessions
     * @throws StorageException on database error
     */
    public function deleteExpiredSessions(?string $beforeTimestamp = null): int {
        try {
            $beforeTimestamp = $beforeTimestamp ?? $this->getTimestamp();
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE expires_at < ?');
            $stmt->execute([$beforeTimestamp]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Mark a session as validated.
     *
     * @param string $sessionId
     * @return bool True if session was validated, false if not found
     * @throws StorageException on database error
     */
    public function validateSession(string $sessionId): bool {
        try {
            $stmt = $this->pdo->prepare('UPDATE sessions SET validated = 1 WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if a session is validated.
     *
     * @param string $sessionId
     * @return bool True if session exists and is validated, false otherwise
     * @throws StorageException on database error
     */
    public function isSessionValidated(string $sessionId): bool {
        $session = $this->getSessionBySessionId($sessionId);
        if (!$session) {
            return false;
        }
        return $session['validated'];
    }

    /**
     * Check if a session is active (exists and not expired).
     *
     * @param string $sessionId Session ID to check
     * @return bool True if session exists and is not expired, false otherwise
     * @throws StorageException on database error
     */
    public function isSessionActive(string $sessionId): bool {
        try {
            $stmt = $this->pdo->prepare('SELECT expires_at FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                return false;
            }

            // Check if session is expired
            $now = $this->getTimestamp();
            if ($session['expires_at'] < $now) {
                // Delete expired session
                $this->deleteSession($sessionId);
                return false;
            }

            return true;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate a code for a given session.
     *
     * @param string $sessionId Session ID
     * @param string $code The code to validate
     * @return bool True if code is valid and not expired, false otherwise
     * @throws StorageException on database error
     */
    public function validateCode(string $sessionId, string $code): bool {
        try {
            $session = $this->getSessionBySessionId($sessionId);
            if (!$session) {
                return false;
            }

            // Check if code matches
            if ($session['code'] !== $code) {
                return false;
            }

            // Check if code is expired
            $now = $this->getTimestamp();
            if ($session['code_valid_until'] < $now) {
                return false;
            }

            return true;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Regenerate the code for an existing session.
     * Creates a new 6-digit code and updates the validity period.
     *
     * @param string $sessionId
     * @param int $codeValiditySeconds Seconds until code expires (default: 300 = 5 minutes)
     * @return array|null Updated session or null if session not found
     * @throws StorageException on database error
     */
    public function regenerateSessionCode(string $sessionId, int $codeValiditySeconds = 300): ?array {
        try {
            // Check if session exists
            $session = $this->getSessionBySessionId($sessionId);
            if (!$session) {
                return null;
            }

            // Generate new code
            $code = $this->generateCode();

            // Calculate new expiry time
            $codeValidUntil = $this->getTimestamp($codeValiditySeconds);

            // Update session record
            $stmt = $this->pdo->prepare('UPDATE sessions SET code = ?, code_valid_until = ? WHERE session_id = ?');
            $stmt->execute([$code, $codeValidUntil, $sessionId]);

            if ($stmt->rowCount() === 0) {
                return null;
            }

            // Return updated session
            return $this->getSessionBySessionId($sessionId);
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if the given API key has access to the session.
     * Sessions can only be accessed by the same API key that created them.
     *
     * @param string $sessionId
     * @param string $apiKey
     * @return bool True if access is granted, false otherwise
     * @throws StorageException on database error
     */
    public function checkSessionAccess(string $sessionId, string $apiKey): bool {
        try {
            $session = $this->getSessionBySessionId($sessionId);
            
            if (!$session) {
                return false;
            }
            
            // Check if the session was created with the same API key
            return $session['api_key'] === $apiKey;
        } catch (StorageException $e) {
            throw $e;
        }
    }

    /**
     * Create a delegated session for another API key based on a parent session.
     * The delegated session is immediately validated and bound to the parent session's lifecycle.
     *
     * @param string $parentSessionId The parent session ID
     * @param string $targetApiKey The API key for the delegated session
     * @param int $sessionDurationSeconds Duration in seconds (default: 1800 = 30 minutes)
     * @return array The created delegated session record
     * @throws StorageException on database error or if parent session not found/invalid
     */
    public function createDelegatedSession(string $parentSessionId, string $targetApiKey, int $sessionDurationSeconds = 1800): array {
        try {
            // Get parent session to extract user_token
            $parentSession = $this->getSessionBySessionId($parentSessionId);
            
            if (!$parentSession) {
                throw new StorageException('INVALID_PARENT_SESSION', 'Parent session not found or expired');
            }
            
            if (!$parentSession['validated']) {
                throw new StorageException('INVALID_PARENT_SESSION', 'Parent session must be validated');
            }
            
            $userToken = $parentSession['user_token'];
            
            // Generate unique session ID
            do {
                $sessionId = $this->generateSessionId();
                $stmt = $this->pdo->prepare('SELECT session_id FROM sessions WHERE session_id = ?');
                $stmt->execute([$sessionId]);
            } while ($stmt->fetch());

            // Generate dummy code (not used since session is pre-validated)
            $code = $this->generateCode();

            $expiresAt = $this->getTimestamp($sessionDurationSeconds);
            $codeValidUntil = $this->getTimestamp(0); // Already expired since not needed
            $createdAt = $this->getTimestamp();

            // Insert session record with validated=1 and parent_session_id
            $stmt = $this->pdo->prepare(
                'INSERT INTO sessions (session_id, user_token, code, code_valid_until, expires_at, session_duration, validated, created_at, api_key, parent_session_id) 
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
            );
            $stmt->execute([
                $sessionId, 
                $userToken, 
                $code, 
                $codeValidUntil, 
                $expiresAt, 
                $sessionDurationSeconds, 
                $createdAt, 
                $targetApiKey, 
                $parentSessionId
            ]);

            return [
                'session_id' => $sessionId,
                'user_token' => $userToken,
                'code' => $code,
                'code_valid_until' => $codeValidUntil,
                'expires_at' => $expiresAt,
                'session_duration' => $sessionDurationSeconds,
                'validated' => true,
                'created_at' => $createdAt,
                'api_key' => $targetApiKey,
                'parent_session_id' => $parentSessionId
            ];
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }
}
