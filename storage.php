<?php

require_once __DIR__ . '/exceptions.php';

/**
 * Database
 *
 * SQLite database storage handler for user authentication data.
 * Stores user records identified by token with code and timestamps.
 * Implemented as a singleton.
 */
class Database {
    /** @var Database|null */
    private static ?Database $instance = null;

    /** @var PDO */
    private PDO $pdo;

    /** @var string */
    private string $databasePath;

    /**
     * Private constructor.
     *
     * @param string $databasePath
     * @throws StorageException
     */
    private function __construct(string $databasePath) {
        $this->databasePath = $databasePath;
        $this->initDatabase();
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
                    code TEXT NOT NULL,
                    code_valid_until TEXT NOT NULL,
                    last_activity_at TEXT NOT NULL
                )
            ";
            $this->pdo->exec($sql);
            
            // Create sessions table if it doesn't exist
            $sessionSql = "
                CREATE TABLE IF NOT EXISTS sessions (
                    session_id TEXT PRIMARY KEY,
                    user_token TEXT NOT NULL,
                    expires_at TEXT NOT NULL,
                    session_duration INTEGER NOT NULL DEFAULT 1800,
                    created_at TEXT NOT NULL,
                    FOREIGN KEY (user_token) REFERENCES users(token) ON DELETE CASCADE
                )
            ";
            $this->pdo->exec($sessionSql);
            
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate a random 6-digit numeric code.
     *
     * @return string
     */
    private function generateCode(): string {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a secure session ID (32 characters, lowercase alphanumeric, URL-safe).
     *
     * @return string
     */
    private function generateSessionId(): string {
        // Use random_bytes and encode to ensure URL-safe, lowercase characters
        // We'll use a simple approach: base32 encoding with lowercase alphabet
        $bytes = random_bytes(20); // 20 bytes = 160 bits
        $result = '';
        $chars = 'abcdefghijklmnopqrstuvwxyz234567'; // Base32 lowercase alphabet
        
        for ($i = 0; $i < 20; $i++) {
            $byte = ord($bytes[$i]);
            $result .= $chars[$byte % 32];
        }
        
        // Add some additional characters to reach exactly 32 chars
        $additionalBytes = random_bytes(12);
        for ($i = 0; $i < 12; $i++) {
            $byte = ord($additionalBytes[$i]);
            $result .= $chars[$byte % 32];
        }
        
        return $result;
    }

    /**
     * Public: retrieve a user record by token. Returns null if not found.
     *
     * @param string $token
     * @return array|null
     * @throws StorageException on database error
     */
    public function getUserByToken(string $token): ?array {
        try {
            $stmt = $this->pdo->prepare('SELECT token, code, code_valid_until, last_activity_at FROM users WHERE token = ?');
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                return null;
            }
            
            return [
                'token' => $row['token'],
                'code' => $row['code'],
                'code_valid_until' => $row['code_valid_until'],
                'last_activity_at' => $row['last_activity_at']
            ];
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: create a new user record with auto-generated code.
     * Generates a random 6-digit code and sets validity period.
     *
     * @param string $token
     * @param int $codeValiditySeconds Seconds until code expires (default: 300 = 5 minutes)
     * @return array The created user record with generated code
     * @throws StorageException on database error or invalid parameters
     */
    public function createUser(string $token, int $codeValiditySeconds = 300): array {
        try {
            // Validate token is not empty
            if (empty($token)) {
                throw new StorageException('STORAGE_ERROR', 'Token cannot be empty');
            }
            
            // Generate 6-digit code
            $code = $this->generateCode();
            
            // Calculate expiry time
            $now = time();
            $codeValidUntil = gmdate('c', $now + $codeValiditySeconds);
            $lastActivityAt = gmdate('c', $now);
            
            // Insert user record
            $stmt = $this->pdo->prepare('INSERT INTO users (token, code, code_valid_until, last_activity_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([$token, $code, $codeValidUntil, $lastActivityAt]);
            
            return [
                'token' => $token,
                'code' => $code,
                'code_valid_until' => $codeValidUntil,
                'last_activity_at' => $lastActivityAt
            ];
        } catch (PDOException $e) {
            // Check if it's a duplicate key error
            if ($e->getCode() == '23000') {
                throw new StorageException('STORAGE_ERROR', 'User with this token already exists');
            }
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: regenerate the code for an existing user.
     * Creates a new 6-digit code and updates the validity period.
     *
     * @param string $token
     * @param int $codeValiditySeconds Seconds until code expires (default: 300 = 5 minutes)
     * @return array|null The updated user record or null if token not found
     * @throws StorageException on database error
     */
    public function regenerateCode(string $token, int $codeValiditySeconds = 300): ?array {
        try {
            // Check if user exists
            $existing = $this->getUserByToken($token);
            if (!$existing) {
                return null;
            }
            
            // Generate new code
            $code = $this->generateCode();
            
            // Calculate new expiry time
            $now = time();
            $codeValidUntil = gmdate('c', $now + $codeValiditySeconds);
            
            // Update user record
            $stmt = $this->pdo->prepare('UPDATE users SET code = ?, code_valid_until = ? WHERE token = ?');
            $stmt->execute([$code, $codeValidUntil, $token]);
            
            return [
                'token' => $token,
                'code' => $code,
                'code_valid_until' => $codeValidUntil,
                'last_activity_at' => $existing['last_activity_at']
            ];
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: delete a user record by token.
     *
     * @param string $token
     * @return bool True if a record was deleted, false if token didn't exist
     * @throws StorageException on database error
     */
    public function deleteUser(string $token): bool {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM users WHERE token = ?');
            $stmt->execute([$token]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: update last_activity_at for a user via session ID and refresh the session.
     * This renews the session by creating a new session ID and deleting the old one.
     *
     * @param string $sessionId Session ID
     * @return string|null The new session ID or null if session not found/expired
     * @throws StorageException on database error
     */
    public function touchUser(string $sessionId): ?string {
        try {
            // Get session to find user
            $session = $this->getSessionBySessionId($sessionId);
            if (!$session) {
                return null;
            }

            // Update user's last_activity_at
            $now = gmdate('c');
            $stmt = $this->pdo->prepare('UPDATE users SET last_activity_at = ? WHERE token = ?');
            $stmt->execute([$now, $session['user_token']]);

            // Refresh the session (creates new session ID, extends expiry)
            $newSession = $this->touchSession($sessionId);
            
            return $newSession ? $newSession['session_id'] : null;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: delete all users where code_valid_until is before the given timestamp.
     *
     * @param string|null $beforeTimestamp ISO 8601 timestamp (defaults to now if null)
     * @return int Number of deleted records
     * @throws StorageException on database error
     */
    public function deleteExpiredCodes(?string $beforeTimestamp = null): int {
        try {
            $beforeTimestamp = $beforeTimestamp ?? gmdate('c');
            $stmt = $this->pdo->prepare('DELETE FROM users WHERE code_valid_until < ?');
            $stmt->execute([$beforeTimestamp]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    // ========== SESSION MANAGEMENT ==========

    /**
     * Public: create a new session for a user.
     *
     * @param string $userToken The user token to create a session for
     * @param int $sessionDurationSeconds Duration in seconds (default: 1800 = 30 minutes)
     * @return array The created session record
     * @throws StorageException on database error or if user doesn't exist
     */
    public function createSession(string $userToken, int $sessionDurationSeconds = 1800): array {
        try {
            // Verify user exists
            $user = $this->getUserByToken($userToken);
            if (!$user) {
                throw new StorageException('STORAGE_ERROR', 'User not found');
            }

            // Generate unique session ID
            do {
                $sessionId = $this->generateSessionId();
                // Check if session ID already exists (very unlikely but better safe)
                $stmt = $this->pdo->prepare('SELECT session_id FROM sessions WHERE session_id = ?');
                $stmt->execute([$sessionId]);
            } while ($stmt->fetch());

            $now = time();
            $expiresAt = gmdate('c', $now + $sessionDurationSeconds);
            $createdAt = gmdate('c', $now);

            // Insert session record
            $stmt = $this->pdo->prepare('INSERT INTO sessions (session_id, user_token, expires_at, session_duration, created_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$sessionId, $userToken, $expiresAt, $sessionDurationSeconds, $createdAt]);

            return [
                'session_id' => $sessionId,
                'user_token' => $userToken,
                'expires_at' => $expiresAt,
                'session_duration' => $sessionDurationSeconds,
                'created_at' => $createdAt
            ];
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: retrieve a session record by session ID. Returns null if not found or expired.
     *
     * @param string $sessionId
     * @return array|null
     * @throws StorageException on database error
     */
    public function getSessionBySessionId(string $sessionId): ?array {
        try {
            $stmt = $this->pdo->prepare('SELECT session_id, user_token, expires_at, session_duration, created_at FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return null;
            }

            // Check if session is expired
            $now = gmdate('c');
            if ($row['expires_at'] < $now) {
                // Session expired, delete it and return null
                $this->deleteSession($sessionId);
                return null;
            }

            return [
                'session_id' => $row['session_id'],
                'user_token' => $row['user_token'],
                'expires_at' => $row['expires_at'],
                'session_duration' => (int)$row['session_duration'],
                'created_at' => $row['created_at']
            ];
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: get user data by session ID. Returns null if session not found or expired.
     *
     * @param string $sessionId
     * @return array|null User data or null if session invalid
     * @throws StorageException on database error
     */
    public function getUserBySessionId(string $sessionId): ?array {
        $session = $this->getSessionBySessionId($sessionId);
        if (!$session) {
            return null;
        }

        return $this->getUserByToken($session['user_token']);
    }

    /**
     * Private: refresh a session by extending its expiry time and generating a new session ID.
     * This is used internally to implement secure session renewal.
     *
     * @param string $oldSessionId The current session ID
     * @return array|null The new session record or null if old session not found
     * @throws StorageException on database error
     */
    private function touchSession(string $oldSessionId): ?array {
        try {
            // Get existing session
            $session = $this->getSessionBySessionId($oldSessionId);
            if (!$session) {
                return null;
            }

            // Create new session with same duration
            $newSession = $this->createSession($session['user_token'], $session['session_duration']);

            // Delete old session
            $this->deleteSession($oldSessionId);

            return $newSession;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: delete a session by session ID.
     *
     * @param string $sessionId
     * @return bool True if a session was deleted, false if not found
     * @throws StorageException on database error
     */
    public function deleteSession(string $sessionId): bool {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: delete all sessions for a specific user.
     *
     * @param string $userToken
     * @return int Number of deleted sessions
     * @throws StorageException on database error
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
     * Public: delete all expired sessions.
     *
     * @param string|null $beforeTimestamp ISO 8601 timestamp (defaults to now if null)
     * @return int Number of deleted sessions
     * @throws StorageException on database error
     */
    public function deleteExpiredSessions(?string $beforeTimestamp = null): int {
        try {
            $beforeTimestamp = $beforeTimestamp ?? gmdate('c');
            $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE expires_at < ?');
            $stmt->execute([$beforeTimestamp]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }
    
}