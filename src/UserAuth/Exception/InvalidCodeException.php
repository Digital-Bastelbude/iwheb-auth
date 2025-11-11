<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Exception;
use iwhebAPI\SessionManagement\Exception\NotFoundException;

/**
 * InvalidCodeException
 *
 * Exception thrown when a verification code is invalid or expired.
 * Returns generic 404 for security.
 */
class InvalidCodeException extends NotFoundException {
    public function __construct(string $message = 'Invalid or expired code') {
        parent::__construct('INVALID_CODE', $message);
    }
}
