<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Database\Repository;

use PDO;

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
     * Generate a secure session ID (32 characters, lowercase hex, URL-safe).
     *
     * Uses 16 random bytes encoded as hex (128 bits entropy).
     * Sufficient for session IDs with low collision probability.
     *
     * @return string 32-character lowercase hexadecimal string
     */
    protected function generateSessionId(): string {
        return bin2hex(random_bytes(16));
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
