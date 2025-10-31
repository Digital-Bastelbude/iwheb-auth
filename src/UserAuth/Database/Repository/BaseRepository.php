<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database\Repository;

use PDO;
use StorageException;

/**
 * BaseRepository
 * 
 * Abstract base class for all repositories. Provides shared PDO access
 * and utility methods for code/session ID generation.
 */
abstract class BaseRepository {
    protected PDO $pdo;
    
    /**
     * Constructor with PDO injection.
     * 
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Generate a random 6-digit numeric code.
     *
     * @return string
     */
    protected function generateCode(): string {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a secure session ID (32 characters, lowercase alphanumeric, URL-safe).
     *
     * @return string
     */
    protected function generateSessionId(): string {
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
     * Get current UTC timestamp in ISO 8601 format.
     *
     * @param int $offsetSeconds Optional offset in seconds
     * @return string
     */
    protected function getTimestamp(int $offsetSeconds = 0): string {
        return gmdate('Y-m-d\TH:i:s\Z', time() + $offsetSeconds);
    }
}
