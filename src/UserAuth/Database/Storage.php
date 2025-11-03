<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database;

use IwhebAPI\UserAuth\Database\Repository\{
    UserRepository, 
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
 * SQLite database storage handler for user authentication data.
 * Facade pattern: delegates to specialized repositories while maintaining
 * backward compatibility with existing API.
 * Implemented as a singleton.
 */
class Database {
    /** @var Database|null */
    private static ?Database $instance = null;

    /** @var PDO */
    private PDO $pdo;

    /** @var string */
    private string $databasePath;
    
    private UserRepository $userRepository;
    private SessionOperationsRepository $sessionOperations;
    private SessionValidationRepository $sessionValidation;
    private SessionDelegationRepository $sessionDelegation;

    /**
     * Private constructor.
     */
    private function __construct(string $databasePath) {
        $this->databasePath = $databasePath;
        $this->initDatabase();
        
        // Initialize repositories
        $this->userRepository = new UserRepository($this->pdo);
        $this->sessionOperations = new SessionOperationsRepository($this->pdo);
        $this->sessionValidation = new SessionValidationRepository($this->pdo, $this->sessionOperations);
        $this->sessionDelegation = new SessionDelegationRepository($this->pdo, $this->sessionOperations);
    }

    /**
     * Get the singleton instance.
     *
     * @param string|null $databasePath Optional path used on first initialization.
     * @return Database
     * @throws StorageException
     */
    public static function getInstance(?string $databasePath = null): Database {
        if (self::$instance === null) {
            $databasePath = $databasePath ?? (defined('DATA_FILE') ? str_replace('.json', '.db', DATA_FILE) : (__DIR__ . '/data.db'));
            self::$instance = new Database($databasePath);
        }
        return self::$instance;
    }

    /**
     * Reset the singleton (for tests).
     */
    public static function resetInstance(): void {
        self::$instance = null;
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
            
            // Create users table if it doesn't exist
            $sql = "
                CREATE TABLE IF NOT EXISTS users (
                    token TEXT PRIMARY KEY,
                    last_activity_at TEXT NOT NULL
                )
            ";
            $this->pdo->exec($sql);
            
            // Create sessions table if it doesn't exist
            $sessionSql = "
                CREATE TABLE IF NOT EXISTS sessions (
                    session_id TEXT PRIMARY KEY,
                    user_token TEXT NOT NULL,
                    code TEXT NOT NULL,
                    code_valid_until TEXT NOT NULL,
                    expires_at TEXT NOT NULL,
                    session_duration INTEGER NOT NULL DEFAULT 1800,
                    validated INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    api_key TEXT NOT NULL,
                    FOREIGN KEY (user_token) REFERENCES users(token) ON DELETE CASCADE
                )
            ";
            $this->pdo->exec($sessionSql);
            
            // Migration: Add api_key column if it doesn't exist (for existing databases)
            try {
                $this->pdo->exec("ALTER TABLE sessions ADD COLUMN api_key TEXT NOT NULL DEFAULT ''");
            } catch (PDOException $e) {
                // Column already exists, ignore error
            }
            
            // Migration: Add parent_session_id column if it doesn't exist (for delegated sessions)
            try {
                $this->pdo->exec("ALTER TABLE sessions ADD COLUMN parent_session_id TEXT DEFAULT NULL");
            } catch (PDOException $e) {
                // Column already exists, ignore error
            }
            
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database initialization failed: ' . $e->getMessage());
        }
    }

    // ========== USER MANAGEMENT (delegated to UserRepository) ==========

    /**
     * Retrieve a user record by token. Returns null if not found.
     *
     * @param string $token
     * @return array|null
     * @throws StorageException on database error
     */
    public function getUserByToken(string $token): ?array {
        return $this->userRepository->getUserByToken($token);
    }

    /**
     * Create a new user record.
     *
     * @param string $token
     * @return array The created user record
     * @throws StorageException on database error or invalid parameters
     */
    public function createUser(string $token): array {
        return $this->userRepository->createUser($token);
    }

    /**
     * Delete a user record by token.
     *
     * @param string $token
     * @return bool True if a record was deleted, false if token didn't exist
     * @throws StorageException on database error
     */
    public function deleteUser(string $token): bool {
        return $this->userRepository->deleteUser($token);
    }

    // ========== SESSION MANAGEMENT ==========

    public function createSession(string $userToken, string $apiKey, int $sessionDurationSeconds = 1800, int $codeValiditySeconds = 300): array {
        $user = $this->userRepository->getUserByToken($userToken);
        if (!$user) {
            throw new StorageException('STORAGE_ERROR', 'User not found');
        }
        return $this->sessionOperations->createSession($userToken, $apiKey, $sessionDurationSeconds, $codeValiditySeconds);
    }

    public function createDelegatedSession(string $parentSessionId, string $targetApiKey, int $sessionDurationSeconds = 1800): array {
        return $this->sessionDelegation->createDelegatedSession($parentSessionId, $targetApiKey, $sessionDurationSeconds);
    }

    public function getSessionBySessionId(string $sessionId): ?array {
        return $this->sessionOperations->getSessionBySessionId($sessionId);
    }

    public function getUserBySessionId(string $sessionId): ?array {
        $session = $this->sessionOperations->getSessionBySessionId($sessionId);
        return $session ? $this->userRepository->getUserByToken($session['user_token']) : null;
    }

    public function deleteSession(string $sessionId): bool {
        return $this->sessionOperations->deleteSession($sessionId);
    }

    public function deleteUserSessions(string $userToken): int {
        return $this->sessionOperations->deleteUserSessions($userToken);
    }

    public function deleteExpiredSessions(?string $beforeTimestamp = null): int {
        return $this->sessionOperations->deleteExpiredSessions($beforeTimestamp);
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
        return $this->sessionValidation->checkSessionAccess($sessionId, $apiKey);
    }

    public function touchUser(string $sessionId): ?string {
        $session = $this->sessionOperations->getSessionBySessionId($sessionId);
        if (!$session) {
            return null;
        }
        $this->userRepository->touchUser($session['user_token']);
        $newSession = $this->sessionOperations->touchSession($sessionId, $session['user_token'], $session['api_key']);
        return $newSession ? $newSession['session_id'] : null;
    }
}
