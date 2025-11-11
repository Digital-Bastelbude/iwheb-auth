<?php
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Exception\Http;

use Exception;
use Throwable;

/**
 * WeblingException
 *
 * Exception thrown when Webling API operations fail.
 */
class WeblingException extends Exception {
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
