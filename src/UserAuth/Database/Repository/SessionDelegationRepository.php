<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database\Repository;

use IwhebAPI\UserAuth\Exception\Database\StorageException;
use PDO;
use PDOException;

/**
 * SessionDelegationRepository
 * 
 * Delegated session operations: create pre-validated sessions for other API keys.
 */
class SessionDelegationRepository extends BaseRepository {
    private SessionCrudRepository $crud;

    public function __construct(PDO $pdo, SessionCrudRepository $crud) {
        parent::__construct($pdo);
        $this->crud = $crud;
    }

    /**
     * Create delegated session for another API key.
     * Session is immediately validated and bound to parent's lifecycle.
     */
    public function createDelegatedSession(string $parentSessionId, string $targetApiKey, int $sessionDurationSeconds = 1800): array {
        try {
            $parentSession = $this->crud->getSessionBySessionId($parentSessionId);
            
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

            $code = $this->generateCode();
            $expiresAt = $this->getTimestamp($sessionDurationSeconds);
            $codeValidUntil = $this->getTimestamp(0); // Expired (not needed)
            $createdAt = $this->getTimestamp();

            // Insert with validated=1 and parent_session_id
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
