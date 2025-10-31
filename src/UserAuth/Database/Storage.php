<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database;

use IwhebAPI\UserAuth\Database\Repository\{UserRepository, SessionRepository};
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
    
    /** @var UserRepository */
    private UserRepository $userRepository;
    
    /** @var SessionRepository */
    private SessionRepository $sessionRepository;

    /**
     * Private constructor.
     *
     * @param string $databasePath
     * @throws StorageException
     */
    private function __construct(string $databasePath) {
        $this->databasePath = $databasePath;
        $this->initDatabase();
        
        // Initialize repositories
        $this->userRepository = new UserRepository($this->pdo);
        $this->sessionRepository = new SessionRepository($this->pdo);
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

    /**
     * Update last_activity_at for a user via session ID and refresh the session.
     * This renews the session by creating a new session ID and deleting the old one.
     *
     * @param string $sessionId Session ID
     * @return string|null The new session ID or null if session not found/expired
     * @throws StorageException on database error
     */
    public function touchUser(string $sessionId): ?string {
        // Get session to find user
        $session = $this->sessionRepository->getSessionBySessionId($sessionId);
        if (!$session) {
            return null;
        }

        // Update user's last_activity_at
        $this->userRepository->touchUser($session['user_token']);

        // Refresh the session (creates new session ID, extends expiry)
        $newSession = $this->sessionRepository->touchSession($sessionId, $session['user_token'], $session['api_key']);
        
        return $newSession ? $newSession['session_id'] : null;
    }

    // ========== SESSION MANAGEMENT (delegated to SessionRepository) ==========

    /**
     * Create a new session for a user with auto-generated code.
     *
     * @param string $userToken The user token to create a session for
     * @param string $apiKey The API key used to create this session
     * @param int $sessionDurationSeconds Duration in seconds (default: 1800 = 30 minutes)
     * @param int $codeValiditySeconds Seconds until code expires (default: 300 = 5 minutes)
     * @return array The created session record with code
     * @throws StorageException on database error or if user doesn't exist
     */
    public function createSession(string $userToken, string $apiKey, int $sessionDurationSeconds = 1800, int $codeValiditySeconds = 300): array {
        // Verify user exists
        $user = $this->userRepository->getUserByToken($userToken);
        if (!$user) {
            throw new StorageException('STORAGE_ERROR', 'User not found');
        }

        return $this->sessionRepository->createSession($userToken, $apiKey, $sessionDurationSeconds, $codeValiditySeconds);
    }

    /**
     * Retrieve a session record by session ID. Returns null if not found or expired.
     *
     * @param string $sessionId
     * @return array|null
     * @throws StorageException on database error
     */
    public function getSessionBySessionId(string $sessionId): ?array {
        return $this->sessionRepository->getSessionBySessionId($sessionId);
    }

    /**
     * Get user data by session ID. Returns null if session not found or expired.
     *
     * @param string $sessionId
     * @return array|null User data or null if session invalid
     * @throws StorageException on database error
     */
    public function getUserBySessionId(string $sessionId): ?array {
        $session = $this->sessionRepository->getSessionBySessionId($sessionId);
        if (!$session) {
            return null;
        }

        return $this->userRepository->getUserByToken($session['user_token']);
    }

    /**
     * Delete a session by session ID.
     *
     * @param string $sessionId
     * @return bool True if a session was deleted, false if not found
     * @throws StorageException on database error
     */
    public function deleteSession(string $sessionId): bool {
        return $this->sessionRepository->deleteSession($sessionId);
    }

    /**
     * Delete all sessions for a specific user.
     *
     * @param string $userToken
     * @return int Number of deleted sessions
     * @throws StorageException on database error
     */
    public function deleteUserSessions(string $userToken): int {
        return $this->sessionRepository->deleteUserSessions($userToken);
    }

    /**
     * Delete all expired sessions.
     *
     * @param string|null $beforeTimestamp ISO 8601 timestamp (defaults to now if null)
     * @return int Number of deleted sessions
     * @throws StorageException on database error
     */
    public function deleteExpiredSessions(?string $beforeTimestamp = null): int {
        return $this->sessionRepository->deleteExpiredSessions($beforeTimestamp);
    }

    /**
     * Mark a session as validated.
     *
     * @param string $sessionId
     * @return bool True if session was validated, false if not found
     * @throws StorageException on database error
     */
    public function validateSession(string $sessionId): bool {
        return $this->sessionRepository->validateSession($sessionId);
    }

    /**
     * Check if a session is validated.
     *
     * @param string $sessionId
     * @return bool True if session exists and is validated, false otherwise
     * @throws StorageException on database error
     */
    public function isSessionValidated(string $sessionId): bool {
        return $this->sessionRepository->isSessionValidated($sessionId);
    }

    /**
     * Check if a session is active (exists and not expired).
     *
     * @param string $sessionId Session ID to check
     * @return bool True if session exists and is not expired, false otherwise
     * @throws StorageException on database error
     */
    public function isSessionActive(string $sessionId): bool {
        return $this->sessionRepository->isSessionActive($sessionId);
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
        return $this->sessionRepository->validateCode($sessionId, $code);
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
        return $this->sessionRepository->regenerateSessionCode($sessionId, $codeValiditySeconds);
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
        return $this->sessionRepository->checkSessionAccess($sessionId, $apiKey);
    }
}