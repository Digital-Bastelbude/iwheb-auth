<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database\Repository;

use IwhebAPI\UserAuth\Exception\Database\StorageException;
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
     * 
     * If an old session ID is provided:
     * 1. Child sessions are reparented to the new session
     * 2. Old session is deleted (children are preserved)
     * 
     * @param string $userToken Encrypted Webling user ID
     * @param string $apiKey API key for this session
     * @param int $sessionDurationSeconds Session lifetime in seconds
     * @param int $codeValiditySeconds Code validity in seconds
     * @param string|null $oldSessionId Optional: Session to replace
     * @return array New session data
     */
    public function createSession(
        string $userToken, 
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

            $stmt = $this->pdo->prepare('INSERT INTO sessions (session_id, user_token, code, code_valid_until, expires_at, session_duration, validated, created_at, api_key) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)');
            $stmt->execute([$sessionId, $userToken, $code, $codeValidUntil, $expiresAt, $sessionDurationSeconds, $createdAt, $apiKey]);

            // If replacing old session: reparent children, then delete old
            if ($oldSessionId !== null) {
                $this->reparentChildSessions($oldSessionId, $sessionId);
                $this->deleteSession($oldSessionId);
            }

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
     * Delete duplicate sessions for same user/API-key combinations
     * 
     * Decrypts all session user tokens and removes duplicates where
     * the same Webling user ID is logged in with the same API key
     * multiple times. Keeps the most recent session.
     * 
     * This is a maintenance operation for cleanup only.
     * NOT callable from any route/controller!
     * 
     * @param \IwhebAPI\UserAuth\Database\UidEncryptor $uidEncryptor Encryptor to decrypt user tokens
     * @return int Number of deleted sessions
     */
    public function deleteDuplicateUserApiKeySessions($uidEncryptor): int {
        try {
            // 1. Fetch all sessions
            $stmt = $this->pdo->query("
                SELECT session_id, user_token, api_key, created_at 
                FROM sessions 
                ORDER BY created_at DESC
            ");
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 2. Group by decrypted UID + API key
            $seen = [];
            $toDelete = [];
            
            foreach ($sessions as $session) {
                $weblingUserId = $uidEncryptor->decrypt($session['user_token']);
                if ($weblingUserId === null) continue;
                
                $key = $weblingUserId . '|' . $session['api_key'];
                
                if (isset($seen[$key])) {
                    // Duplicate found - mark older one for deletion
                    $toDelete[] = $session['session_id'];
                } else {
                    $seen[$key] = true;
                }
            }
            
            // 3. Delete duplicates
            if (empty($toDelete)) {
                return 0;
            }
            
            $placeholders = str_repeat('?,', count($toDelete) - 1) . '?';
            $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE session_id IN ($placeholders)");
            $stmt->execute($toDelete);
            
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
