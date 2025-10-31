<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Exception;

/**
 * NotFoundException
 *
 * Base exception for all "not found" errors.
 * Used for security-conscious error handling where specific details should not be revealed.
 * All subclasses will return a generic 404 response to the client.
 */
class NotFoundException extends \Exception {
    public string $reason;
    
    public function __construct(string $reason = 'NOT_FOUND', string $message = '') {
        $this->reason = $reason;
        parent::__construct($message ?: $reason);
    }
}
