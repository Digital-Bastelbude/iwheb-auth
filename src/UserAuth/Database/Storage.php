<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database;

use IwhebAPI\UserAuth\Database\Repository\{
    SessionOperationsRepository, 
    SessionValidationRepository, 
    SessionDelegationRepository
};
use IwhebAPI\UserAuth\Exception\Database\StorageException;
use PDO;
use PDOException;

/**
 * Database
 *
 * Main database facade for user authentication and session management.
 * 
 * Delegates operations to specialized repositories:
 * - SessionOperationsRepository: Session lifecycle (CRUD)
 * - SessionValidationRepository: Code validation and session state
 * - SessionDelegationRepository: Delegated session handling
 * 
 * Uses SQLite for data persistence.
 */
class Database {
    /** @var PDO */
    private PDO $pdo;

    /** @var string */
    private string $databasePath;
    
    private SessionOperationsRepository $sessionOperations;
    private SessionValidationRepository $sessionValidation;
    private SessionDelegationRepository $sessionDelegation;

    /**
     * Constructor.
     * 
     * @param string $databasePath Path to SQLite database file
     * @throws StorageException on database initialization failure
     */
    public function __construct(string $databasePath) {
        $this->databasePath = $databasePath;
        $this->initDatabase();
        
        // Initialize repositories
        $this->sessionOperations = new SessionOperationsRepository($this->pdo);
        $this->sessionValidation = new SessionValidationRepository($this->pdo, $this->sessionOperations);
        $this->sessionDelegation = new SessionDelegationRepository($this->pdo, $this->sessionOperations);
    }

    /**
     * Create Database instance from environment configuration.
     * 
     * Reads database path from environment variables or uses defaults.
     * Priority: DATABASE_PATH env var > DATA_FILE constant > default path
     * 
     * @return self
     * @throws StorageException on database initialization failure
     */
    public static function fromEnv(): self {
        $databasePath = getenv('DATABASE_PATH');
        
        if (!$databasePath) {
            $databasePath = defined('DATA_FILE') ? DATA_FILE : __DIR__ . '/../../storage/data.db';
        }
        
        return new self($databasePath);
    }

    /**
     * Initialize the SQLite database and create table if it doesn't exist.
     *
     * @throws StorageException
     */
    private function initDatabase(): void {
        try {
            // Create directory if it doesn't exist
            $dir = dirname($this->databasePath);
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0775, true)) {
                    throw new StorageException('STORAGE_ERROR', 'Failed to create database directory');
                }
            }

            // Connect to SQLite database
            $this->pdo = new PDO('sqlite:' . $this->databasePath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Enable foreign keys and WAL mode for better performance
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            
            // Create sessions table if it doesn't exist
            $sessionSql = "
                CREATE TABLE IF NOT EXISTS sessions (
                    session_id TEXT PRIMARY KEY,
                    user_token TEXT DEFAULT NULL,
                    code TEXT NOT NULL,
                    code_valid_until TEXT NOT NULL,
                    expires_at TEXT NOT NULL,
                    session_duration INTEGER NOT NULL DEFAULT 1800,
                    validated INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    api_key TEXT NOT NULL DEFAULT '',
                    parent_session_id TEXT DEFAULT NULL
                )
            ";
            $this->pdo->exec($sessionSql);
            
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database initialization failed: ' . $e->getMessage());
        }
    }

    // ========== SESSION MANAGEMENT ==========

    /**
     * Create a new session without user token.
     * User token can be set later using setUserToken().
     *
     * @param string $apiKey API key used to create the session
     * @param int $sessionDurationSeconds Session duration in seconds
     * @param int $codeValiditySeconds Code validity duration in seconds
     * @param string|null $oldSessionId Optional previous session ID for reparenting
     * @return array Session data
     */
    public function createSession(
        string $apiKey, 
        int $sessionDurationSeconds = 1800, 
        int $codeValiditySeconds = 300,
        ?string $oldSessionId = null
    ): array {
        return $this->sessionOperations->createSession($apiKey, $sessionDurationSeconds, $codeValiditySeconds, $oldSessionId);
    }

    /**
     * Set the encrypted user token for an existing session.
     *
     * @param string $sessionId Session ID
     * @param string $encryptedToken Encrypted user token
     * @return bool True if successful, false if session not found
     */
    public function setUserToken(string $sessionId, string $encryptedToken): bool {
        return $this->sessionOperations->setUserToken($sessionId, $encryptedToken);
    }

    /**
     * Rotate a session: Create new session with all data from old session.
     * 
     * Automatically copies user_token, validated status, session_duration, and parent_session_id.
     * Preserves all child sessions by reparenting them to the new session.
     * Deletes the old session.
     *
     * @param string $oldSessionId The session to rotate
     * @param string $apiKey The API key for the new session
     * @return array The new session data
     */
    public function rotateSession(string $oldSessionId, string $apiKey): array {
        return $this->sessionOperations->rotateSession($oldSessionId, $apiKey);
    }

    public function createDelegatedSession(string $parentSessionId, string $targetApiKey, int $sessionDurationSeconds = 1800): array {
        return $this->sessionDelegation->createDelegatedSession($parentSessionId, $targetApiKey, $sessionDurationSeconds);
    }

    public function getSessionBySessionId(string $sessionId): ?array {
        return $this->sessionOperations->getSessionBySessionId($sessionId);
    }

    public function getUserBySessionId(string $sessionId): ?array {
        $session = $this->sessionOperations->getSessionBySessionId($sessionId);
        return $session ? ['token' => $session['user_token']] : null;
    }

    public function deleteSession(string $sessionId): bool {
        return $this->sessionOperations->deleteSession($sessionId);
    }

    public function deleteUserSessions(string $userToken): int {
        return $this->sessionOperations->deleteUserSessions($userToken);
    }

    /**
     * Delete all expired sessions.
     * 
     * Maintenance operation intended for cron jobs/cleanup scripts.
     * Not exposed via HTTP routes.
     * 
     * @param string|null $beforeTimestamp UTC timestamp for expiry check (null = now)
     * @return int Number of deleted sessions
     */
    public function deleteExpiredSessions(?string $beforeTimestamp = null): int {
        return $this->sessionOperations->deleteExpiredSessions($beforeTimestamp);
    }

    /**
     * Extend session expiry time
     * 
     * @param string $sessionId Session ID to extend
     * @param int $sessionDuration Session duration in seconds
     * @return array|null Updated session data
     */
    public function touchSession(string $sessionId, int $sessionDuration = 1800): ?array {
        return $this->sessionOperations->touchSession($sessionId, $sessionDuration);
    }

    public function validateSession(string $sessionId): bool {
        return $this->sessionValidation->validateSession($sessionId);
    }

    public function isSessionValidated(string $sessionId): bool {
        return $this->sessionValidation->isSessionValidated($sessionId);
    }

    public function isSessionActive(string $sessionId): bool {
        return $this->sessionValidation->isSessionActive($sessionId);
    }

    public function validateCode(string $sessionId, string $code): bool {
        return $this->sessionValidation->validateCode($sessionId, $code);
    }

    public function regenerateSessionCode(string $sessionId, int $codeValiditySeconds = 300): ?array {
        return $this->sessionValidation->regenerateSessionCode($sessionId, $codeValiditySeconds);
    }

    public function checkSessionAccess(string $sessionId, string $apiKey): bool {
        $session = $this->sessionOperations->getSessionBySessionId($sessionId);
        return $session && $session['api_key'] === $apiKey;
    }
}