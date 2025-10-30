<?php
/**
 * StorageException
 *
 * Exception thrown when storage operations cannot be completed.
 */
class StorageException extends \Exception {
    public function __construct(string $reason = 'STORAGE_ERROR', string $message = '') {
        parent::__construct($message ?: $reason);
    }
}

/**
 * Application-specific exceptions
 */
class InvalidInputException extends \Exception {
    public function __construct(string $reason = 'INVALID_INPUT', string $message = '') {
        parent::__construct($message ?: $reason);
    }
}

/**
 * NotFoundException
 *
 * Exception thrown when a requested resource is not found.
 * Used for security-conscious error handling where specific details should not be revealed.
 */
class NotFoundException extends \Exception {
    public function __construct(string $reason = 'NOT_FOUND', string $message = '') {
        parent::__construct($message ?: $reason);
    }
}

/**
 * InvalidSessionException
 *
 * Exception thrown when a session is invalid or expired.
 */
class InvalidSessionException extends NotFoundException {
    public function __construct(string $message = 'Invalid or expired session') {
        parent::__construct('INVALID_SESSION', $message);
    }
}

/**
 * InvalidCodeException
 *
 * Exception thrown when a verification code is invalid or expired.
 */
class InvalidCodeException extends NotFoundException {
    public function __construct(string $message = 'Invalid or expired code') {
        parent::__construct('INVALID_CODE', $message);
    }
}

/**
 * UserNotFoundException
 *
 * Exception thrown when a user is not found in Webling.
 */
class UserNotFoundException extends NotFoundException {
    public function __construct(string $message = 'User not found in Webling') {
        parent::__construct('USER_NOT_FOUND', $message);
    }
}


/**
 * Exception class for Webling API errors
 */
class WeblingException extends Exception {
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}