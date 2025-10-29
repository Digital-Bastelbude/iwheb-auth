<?php

require_once __DIR__ . '/exceptions.php';

/**
 * Database
 *
 * SQLite database storage handler for items.
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
            
            // Create items table if it doesn't exist
            $sql = "
                CREATE TABLE IF NOT EXISTS items (
                    id INTEGER PRIMARY KEY,
                    data TEXT NOT NULL,
                    createdAt TEXT NOT NULL,
                    lastUpdatedAt TEXT NOT NULL
                )
            ";
            $this->pdo->exec($sql);
            
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database initialization failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: retrieve a single item by id. Returns null if not found.
     *
     * @param int $id
     * @return array|null
     * @throws StorageException on database error
     */
    public function getItem(int $id): ?array {
        try {
            $stmt = $this->pdo->prepare('SELECT data, createdAt, lastUpdatedAt FROM items WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                return null;
            }
            
            $data = json_decode($row['data'], true);
            if (!is_array($data)) {
                throw new StorageException('STORAGE_ERROR', 'Invalid JSON data in database');
            }
            
            $data['id'] = $id;
            $data['createdAt'] = $row['createdAt'];
            $data['lastUpdatedAt'] = $row['lastUpdatedAt'];
            
            return $data;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: save an item. If $id is null, a new id is assigned. Otherwise item is created/updated.
     * Storage decides whether it's a create or update and handles timestamps.
     * Returns the saved item.
     *
     * @param array $userData
     * @param int|null $id
     * @return array
     * @throws StorageException on database error
     */
    public function saveItem(array $userData, ?int $id = null): array {
        try {
            $now = gmdate('c');
            
            if ($id === null) {
                // Create new item
                $id = $this->getNextId();
                $created = $now;
                
                // Prepare data without id, createdAt, lastUpdatedAt as they are stored separately
                $cleanData = $userData;
                unset($cleanData['id'], $cleanData['createdAt'], $cleanData['lastUpdatedAt']);
                
                $stmt = $this->pdo->prepare('INSERT INTO items (id, data, createdAt, lastUpdatedAt) VALUES (?, ?, ?, ?)');
                $stmt->execute([$id, json_encode($cleanData, JSON_UNESCAPED_UNICODE), $created, $now]);
            } else {
                // Update existing item or create with specific ID
                $stmt = $this->pdo->prepare('SELECT createdAt FROM items WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $created = $row ? $row['createdAt'] : $now;
                
                // Prepare data without id, createdAt, lastUpdatedAt as they are stored separately
                $cleanData = $userData;
                unset($cleanData['id'], $cleanData['createdAt'], $cleanData['lastUpdatedAt']);
                
                $stmt = $this->pdo->prepare('INSERT OR REPLACE INTO items (id, data, createdAt, lastUpdatedAt) VALUES (?, ?, ?, ?)');
                $stmt->execute([$id, json_encode($cleanData, JSON_UNESCAPED_UNICODE), $created, $now]);
            }
            
            // Return the complete item
            $item = $userData;
            $item['id'] = $id;
            $item['createdAt'] = $created;
            $item['lastUpdatedAt'] = $now;
            
            return $item;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: return a list of available ids (as ints)
     *
     * @return int[]
     * @throws StorageException on database error
     */
    public function listIds(): array {
        try {
            $stmt = $this->pdo->query('SELECT id FROM items ORDER BY id');
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Public: compute the next id based on existing ids (highest + 1).
     * Returns 1 when no ids exist.
     *
     * @return int
     * @throws StorageException on database error
     */
    public function getNextId(): int {
        try {
            $stmt = $this->pdo->query('SELECT MAX(id) FROM items');
            $maxId = $stmt->fetchColumn();
            return $maxId ? (int)$maxId + 1 : 1;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database query failed: ' . $e->getMessage());
        }
    }
    
}