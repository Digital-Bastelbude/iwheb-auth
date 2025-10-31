<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Exception\Database;

/**
 * StorageException
 *
 * Exception thrown when database/storage operations cannot be completed.
 */
class StorageException extends \Exception {
    public string $reason;
    
    public function __construct(string $reason = 'STORAGE_ERROR', string $message = '') {
        $this->reason = $reason;
        parent::__construct($message ?: $reason);
    }
}
