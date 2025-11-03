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
     */
    public function createSession(string $userToken, string $apiKey, int $sessionDurationSeconds = 1800, int $codeValiditySeconds = 300): array {
        try {
            // Delete existing unvalidated sessions for this user + API key
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE user_token = ? AND api_key = ? AND validated = 0');
            $stmt->execute([$userToken, $apiKey]);

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
     * Refresh session: extend expiry and generate new session ID.
     */
    public function touchSession(string $oldSessionId, string $userToken, string $apiKey): ?array {
        try {
            $session = $this->getSessionBySessionId($oldSessionId);
            if (!$session) {
                return null;
            }

            // Create new session with same duration
            $newSession = $this->createSession($userToken, $apiKey, $session['session_duration']);

            // Copy validation status
            if ($session['validated']) {
                $stmt = $this->pdo->prepare('UPDATE sessions SET validated = 1 WHERE session_id = ?');
                $stmt->execute([$newSession['session_id']]);
                $newSession['validated'] = true;
            }

            $this->deleteSession($oldSessionId);
            return $newSession;
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
}
