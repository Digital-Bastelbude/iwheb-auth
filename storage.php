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
     * Public: update last_activity_at timestamp for a user to current time.
     *
     * @param string $token
     * @return bool True if record was updated, false if token doesn't exist
     * @throws StorageException on database error
     */
    public function touchUser(string $token): bool {
        try {
            $now = gmdate('c');
            $stmt = $this->pdo->prepare('UPDATE users SET last_activity_at = ? WHERE token = ?');
            $stmt->execute([$now, $token]);
            return $stmt->rowCount() > 0;
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
    
}