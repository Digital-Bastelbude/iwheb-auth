<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database\Repository;

use IwhebAPI\UserAuth\Exception\Database\StorageException;
use IwhebAPI\UserAuth\Database\UidEncryptor;
use PDO;
use PDOException;

/**
 * SessionOperationsRepository
 * 
 * Core session lifecycle operations: creation, persistence, retrieval, refresh, and cleanup.
 */
class SessionOperationsRepository extends BaseRepository {
    /**
     * Initiate a new session with authentication code generation.
     * Session is created without user token - use setUserToken() to assign it later.
     * 
     * If an old session ID is provided:
     * 1. Child sessions are reparented to the new session
     * 2. Old session is deleted (children are preserved)
     * 
     * @param string $apiKey API key for this session
     * @param int $sessionDurationSeconds Session lifetime in seconds
     * @param int $codeValiditySeconds Code validity in seconds
     * @param string|null $oldSessionId Optional: Session to replace
     * @return array New session data
     */
    public function createSession(
        string $apiKey, 
        int $sessionDurationSeconds = 1800, 
        int $codeValiditySeconds = 300,
        ?string $oldSessionId = null
    ): array {
        try {
            // Generate unique session ID
            do {
                $sessionId = $this->generateSessionId();
                $stmt = $this->pdo->prepare('SELECT session_id FROM sessions WHERE session_id = ?');
                $stmt->execute([$sessionId]);
            } while ($stmt->fetch());

            $code = $this->generateCode();
            $expiresAt = $this->getTimestamp($sessionDurationSeconds);
            $codeValidUntil = $this->getTimestamp($codeValiditySeconds);
            $createdAt = $this->getTimestamp();

            $stmt = $this->pdo->prepare('INSERT INTO sessions (session_id, user_token, code, code_valid_until, expires_at, session_duration, validated, created_at, api_key) VALUES (?, NULL, ?, ?, ?, ?, 0, ?, ?)');
            $stmt->execute([$sessionId, $code, $codeValidUntil, $expiresAt, $sessionDurationSeconds, $createdAt, $apiKey]);

            // If replacing old session: reparent children, then delete old
            if ($oldSessionId !== null) {
                $this->reparentChildSessions($oldSessionId, $sessionId);
                $this->deleteSession($oldSessionId);
            }

            return [
                'session_id' => $sessionId,
                'user_token' => null,
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
     * Set the encrypted user token for an existing session.
     *
     * @param string $sessionId Session ID
     * @param string $encryptedToken Encrypted user token
     * @return bool True if successful, false if session not found
     */
    public function setUserToken(string $sessionId, string $encryptedToken): bool {
        try {
            $stmt = $this->pdo->prepare('UPDATE sessions SET user_token = ? WHERE session_id = ?');
            $stmt->execute([$encryptedToken, $sessionId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Failed to set user token: ' . $e->getMessage());
        }
    }

    /**
     * Rotate a session: Create new session with validated status from old session.
     * 
     * Automatically copies:
     * - validated status
     * - session_duration
     * - parent_session_id (if exists)
     * 
     * Does NOT copy user_token - business logic should re-encrypt and set token separately.
     * 
     * Preserves all child sessions by reparenting them to the new session.
     * Deletes the old session.
     *
     * @param string $oldSessionId The session to rotate
     * @param string $apiKey The API key for the new session
     * @return array The new session data (without user_token)
     * @throws StorageException if old session not found or rotation fails
     */
    public function rotateSession(string $oldSessionId, string $apiKey): array {
        try {
            // Get old session
            $oldSession = $this->getSessionBySessionId($oldSessionId);
            if (!$oldSession) {
                throw new StorageException('INVALID_SESSION', 'Session not found');
            }
            
            // Create new session (replaces old, preserves children if parent)
            $newSession = $this->createSession(
                $apiKey,
                $oldSession['session_duration'],
                300, // code validity (not used for rotation, but required)
                $oldSessionId // This triggers child reparenting + deletion of old session
            );
            
            // Copy validated status only (NOT user_token - that's business logic's job)
            if ($oldSession['validated']) {
                // Validated status needs to be set via database update
                $stmt = $this->pdo->prepare('UPDATE sessions SET validated = 1 WHERE session_id = ?');
                $stmt->execute([$newSession['session_id']]);
                $newSession['validated'] = true;
            }
            
            return $newSession;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Failed to rotate session: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve session data by identifier. Returns null if not found or expired.
     * For delegated sessions, validates parent session is still active.
     */
    public function getSessionBySessionId(string $sessionId): ?array {
        try {
            $stmt = $this->pdo->prepare('SELECT session_id, user_token, code, code_valid_until, expires_at, session_duration, validated, created_at, api_key, parent_session_id FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            // Check expiry
            $now = $this->getTimestamp();
            if ($row['expires_at'] < $now) {
                $this->deleteSession($sessionId);
                return null;
            }

            // Validate parent session for delegated sessions
            if ($row['parent_session_id'] !== null) {
                $parentSession = $this->getSessionBySessionId($row['parent_session_id']);
                if ($parentSession === null) {
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
     * Extend session expiry time without creating new session
     * 
     * Simply updates the expires_at timestamp.
     * 
     * @param string $sessionId Session ID to extend
     * @param int $lifetime Session lifetime in seconds
     * @return array|null Updated session data
     */
    public function touchSession(string $sessionId, int $lifetime): ?array {
        try {
            $expiresAt = $this->getTimestamp($lifetime);
            
            $stmt = $this->pdo->prepare("
                UPDATE sessions 
                SET expires_at = ? 
                WHERE session_id = ?
            ");
            $stmt->execute([$expiresAt, $sessionId]);
            
            // Return updated session
            return $this->getSessionBySessionId($sessionId);
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Terminate session and all dependent child sessions.
     */
    public function deleteSession(string $sessionId): bool {
        try {
            // Delete child sessions first (delegated sessions)
            $this->deleteChildSessions($sessionId);
            
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete all child sessions recursively.
     */
    private function deleteChildSessions(string $parentSessionId): int {
        try {
            $stmt = $this->pdo->prepare('SELECT session_id FROM sessions WHERE parent_session_id = ?');
            $stmt->execute([$parentSessionId]);
            $childSessions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $totalDeleted = 0;
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
     * Terminate all sessions for a specific user account.
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
     * Clean up expired sessions from storage.
     * 
     * This is a maintenance operation that should be run periodically
     * via cron job or manual trigger.
     * 
     * @param string|null $beforeTimestamp Optional timestamp for testing (defaults to now)
     * @return int Number of deleted sessions
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
     * Move child sessions from old parent to new parent
     * 
     * This preserves delegated sessions when replacing a parent session.
     * 
     * @param string $oldParentId Old parent session ID
     * @param string $newParentId New parent session ID
     * @return int Number of reparented sessions
     */
    public function reparentChildSessions(string $oldParentId, string $newParentId): int {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE sessions 
                SET parent_session_id = ? 
                WHERE parent_session_id = ?
            ");
            $stmt->execute([$newParentId, $oldParentId]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Terminate all sessions for a specific user and API key combination.
     * Used internally by createSession() and maintenance operations only.
     * NOT exposed via Storage facade!
     */
    private function deleteUserApiKeySessions(string $userToken, string $apiKey): int {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE user_token = ? AND api_key = ?');
            $stmt->execute([$userToken, $apiKey]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }
}
