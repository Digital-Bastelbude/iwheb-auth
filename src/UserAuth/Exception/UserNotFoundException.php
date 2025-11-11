<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Exception;
use iwhebAPI\SessionManagement\Exception\NotFoundException;

/**
 * UserNotFoundException
 *
 * Exception thrown when a user is not found in Webling.
 * Returns generic 404 for security.
 */
class UserNotFoundException extends NotFoundException {
    public function __construct(string $message = 'User not found in Webling') {
        parent::__construct('USER_NOT_FOUND', $message);
    }
}
