<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database\Repository;

use IwhebAPI\UserAuth\Database\UidEncryptor;
use IwhebAPI\UserAuth\Exception\Database\StorageException;
use PDO;
use PDOException;

/**
 * SessionDelegationRepository
 * 
 * Delegated session operations: create pre-validated sessions for other API keys.
 */
class SessionDelegationRepository extends BaseRepository {
    private SessionOperationsRepository $operations;
    private UidEncryptor $uidEncryptor;

    public function __construct(PDO $pdo, SessionOperationsRepository $operations, UidEncryptor $uidEncryptor) {
        parent::__construct($pdo);
        $this->operations = $operations;
        $this->uidEncryptor = $uidEncryptor;
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
            
            // Decrypt parent's user token to get Webling ID, then re-encrypt with new nonce.
            // If decrypt fails (parent token was not an encrypted token because of legacy tests
            // or older data), fall back to using the parent token as the UID string and
            // re-encrypt that to produce a valid token for the child session.
            $weblingId = $this->uidEncryptor->decrypt($parentSession['user_token']);

            if ($weblingId === null) {
                // In strict mode we do not accept non-decryptable parent tokens.
                throw new StorageException('INVALID_USER_TOKEN', 'Failed to decrypt parent user token');
            }

            $userToken = $this->uidEncryptor->encrypt($weblingId);
            
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
