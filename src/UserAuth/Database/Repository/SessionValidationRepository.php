<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database\Repository;

use Error;
use IwhebAPI\UserAuth\Exception\Database\StorageException;
use PDO;
use PDOException;

/**
 * SessionValidationRepository
 * 
 * Session validation operations: validate, check status, code verification, access control.
 */
class SessionValidationRepository extends BaseRepository {
    private SessionOperationsRepository $operations;

    public function __construct(PDO $pdo, SessionOperationsRepository $operations) {
        parent::__construct($pdo);
        $this->operations = $operations;
    }

    /**
     * Mark session as validated.
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
     * Check if session is validated.
     */
    public function isSessionValidated(string $sessionId): bool {
        $session = $this->operations->getSessionBySessionId($sessionId);
        return $session ? $session['validated'] : false;
    }

    /**
     * Check if session is active (exists and not expired).
     */
    public function isSessionActive(string $sessionId): bool {
        try {
            $stmt = $this->pdo->prepare('SELECT expires_at FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                return false;
            }

            $now = $this->getTimestamp();
            if ($session['expires_at'] < $now) {
                $this->operations->deleteSession($sessionId);
                return false;
            }

            return true;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Validate code for a session.
     */
    public function validateCode(string $sessionId, string $code): bool {
        try {
            $session = $this->operations->getSessionBySessionId($sessionId);
            if (!$session) {
                return false;
            }

            if ($session['code'] !== $code) {
                return false;
            }

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
     * Regenerate session code with new validity period.
     */
    public function regenerateSessionCode(string $sessionId, int $codeValiditySeconds = 300): ?array {
        try {
            $session = $this->operations->getSessionBySessionId($sessionId);
            if (!$session) {
                return null;
            }

            $code = $this->generateCode();
            $codeValidUntil = $this->getTimestamp($codeValiditySeconds);

            $stmt = $this->pdo->prepare('UPDATE sessions SET code = ?, code_valid_until = ? WHERE session_id = ?');
            $stmt->execute([$code, $codeValidUntil, $sessionId]);

            if ($stmt->rowCount() === 0) {
                return null;
            }

            return $this->operations->getSessionBySessionId($sessionId);
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if API key has access to session.
     */
    public function checkSessionAccess(string $sessionId, string $apiKey): bool {
        try {
            $session = $this->operations->getSessionBySessionId($sessionId);
            return $session ? $session['api_key'] === $apiKey : false;
        } catch (StorageException $e) {
            throw $e;
        }
    }
}
