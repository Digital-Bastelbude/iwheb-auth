<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Exception\Http;

/**
 * InvalidInputException
 *
 * Exception thrown when request input validation fails.
 * Results in 400 Bad Request response.
 */
class InvalidInputException extends \Exception {
    public string $reason;
    
    public function __construct(string $reason = 'INVALID_INPUT', string $message = '') {
        $this->reason = $reason;
        parent::__construct($message ?: $reason);
    }
}
