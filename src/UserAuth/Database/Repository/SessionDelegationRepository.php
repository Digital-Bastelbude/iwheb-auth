<?php
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Database\Repository;

use iwhebAPI\SessionManagement\Exception\Database\StorageException;
use iwhebAPI\SessionManagement\Database\Repository\{BaseRepository, SessionOperationsRepository}; 
use PDO;
use PDOException;

/**
 * SessionDelegationRepository
 * 
 * Delegated session operations: create pre-validated sessions for other API keys.
 */
class SessionDelegationRepository extends BaseRepository {
    private SessionOperationsRepository $operations;

    public function __construct(PDO $pdo, SessionOperationsRepository $operations) {
        parent::__construct($pdo);
        $this->operations = $operations;
    }

    /**
     * Delete existing child sessions of a parent with specific API key
     * 
     * Ensures only one child session per parent/API-key combination.
     * 
     * @param string $parentSessionId Parent session ID
     * @param string $apiKey Target API key
     * @return int Number of deleted sessions
     */
    private function deleteParentChildByApiKey(string $parentSessionId, string $apiKey): int {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM sessions 
                WHERE parent_session_id = ? 
                AND api_key = ?
            ");
            $stmt->execute([$parentSessionId, $apiKey]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create delegated session for another API key.
     * 
     * Rules:
     * - Only one child session per parent/API-key combination allowed
     * - Existing children with the same API key are deleted before creating new one
     * - Parent session must NOT be a child itself (no nested delegation)
     * 
     * @param string $parentSessionId Parent session ID
     * @param string $targetApiKey Target API key for delegated session
     * @param int $sessionDurationSeconds Session duration in seconds
     * @return array New delegated session data
     * @throws StorageException If parent is a child session or not found
     */
    public function createDelegatedSession(string $parentSessionId, string $targetApiKey, int $sessionDurationSeconds = 1800): array {
        try {
            $parentSession = $this->operations->getSessionBySessionId($parentSessionId);
            
            if (!$parentSession) {
                throw new StorageException('INVALID_PARENT_SESSION', 'Parent session not found or expired');
            }
            
            if (!$parentSession['validated']) {
                throw new StorageException('INVALID_PARENT_SESSION', 'Parent session must be validated');
            }
            
            // Check if parent is itself a child (no nested delegation allowed!)
            if (isset($parentSession['parent_session_id']) && $parentSession['parent_session_id'] !== null) {
                throw new StorageException('INVALID_PARENT_SESSION', 'Cannot delegate from a child session');
            }
            
            // Get parent's user token - it will be re-encrypted by the business logic layer
            $parentUserToken = $parentSession['user_token'];
            
            if ($parentUserToken === null) {
                throw new StorageException('INVALID_USER_TOKEN', 'Parent session has no user token');
            }
            
            // Delete existing child sessions for this parent/API-key combination
            // This ensures: one parent + one API key = one child session
            $this->deleteParentChildByApiKey($parentSessionId, $targetApiKey);
            
            // Generate unique session ID
            do {
                $sessionId = $this->generateSessionId();
                $stmt = $this->pdo->prepare('SELECT session_id FROM sessions WHERE session_id = ?');
                $stmt->execute([$sessionId]);
            } while ($stmt->fetch());

            $code = $this->generateCode();
            $expiresAt = $this->getTimestamp($sessionDurationSeconds);
            $codeValidUntil = $this->getTimestamp(0); // Expired (not needed)
            $createdAt = $this->getTimestamp();

            // Insert with validated=1, parent_session_id, and NULL user_token
            // Business logic will set the token later
            $stmt = $this->pdo->prepare(
                'INSERT INTO sessions (session_id, user_token, code, code_valid_until, expires_at, session_duration, validated, created_at, api_key, parent_session_id) 
                 VALUES (?, NULL, ?, ?, ?, ?, 1, ?, ?, ?)'
            );
            $stmt->execute([
                $sessionId, 
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
                'user_token' => null,
                'code' => $code,
                'code_valid_until' => $codeValidUntil,
                'expires_at' => $expiresAt,
                'session_duration' => $sessionDurationSeconds,
                'validated' => true,
                'created_at' => $createdAt,
                'api_key' => $targetApiKey,
                'parent_session_id' => $parentSessionId,
                'parent_user_token' => $parentUserToken  // Return parent token for business logic
            ];
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }
}
