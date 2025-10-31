<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database\Repository;

use IwhebAPI\UserAuth\Exception\Database\StorageException;
use PDO;
use PDOException;

/**
 * UserRepository
 * 
 * Handles user-related database operations.
 */
class UserRepository extends BaseRepository {
    /**
     * Retrieve a user record by token. Returns null if not found.
     *
     * @param string $token
     * @return array|null User data with 'token' and 'last_activity_at'
     * @throws StorageException on database error
     */
    public function getUserByToken(string $token): ?array {
        try {
            $stmt = $this->pdo->prepare('SELECT token, last_activity_at FROM users WHERE token = ?');
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                return null;
            }
            
            return [
                'token' => $row['token'],
                'last_activity_at' => $row['last_activity_at']
            ];
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a new user record.
     *
     * @param string $token Unique user token
     * @return array The created user record
     * @throws StorageException on database error or invalid parameters
     */
    public function createUser(string $token): array {
        try {
            // Validate token is not empty
            if (empty($token)) {
                throw new StorageException('STORAGE_ERROR', 'Token cannot be empty');
            }
            
            $lastActivityAt = $this->getTimestamp();
            
            // Insert user record
            $stmt = $this->pdo->prepare('INSERT INTO users (token, last_activity_at) VALUES (?, ?)');
            $stmt->execute([$token, $lastActivityAt]);
            
            return [
                'token' => $token,
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
     * Delete a user record by token.
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
     * Update last_activity_at timestamp for a user.
     *
     * @param string $userToken The user token
     * @return bool True if updated successfully
     * @throws StorageException on database error
     */
    public function touchUser(string $userToken): bool {
        try {
            $now = $this->getTimestamp();
            $stmt = $this->pdo->prepare('UPDATE users SET last_activity_at = ? WHERE token = ?');
            $stmt->execute([$now, $userToken]);
            
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new StorageException('STORAGE_ERROR', 'Database operation failed: ' . $e->getMessage());
        }
    }
}
