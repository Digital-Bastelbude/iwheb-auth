<?php
declare(strict_types=1);

namespace iwhebAPI\SessionManagement\Exception;

/**
 * InvalidSessionException
 *
 * Exception thrown when a session is invalid, expired, or access is denied.
 * Returns generic 404 for security.
 */
class InvalidSessionException extends NotFoundException {
    public function __construct(string $message = 'Invalid or expired session') {
        parent::__construct('INVALID_SESSION', $message);
    }
}
