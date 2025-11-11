<?php
declare(strict_types=1);

namespace iwhebAPI\SessionManagement\Http;

use Logger;

/**
 * Response
 *
 * Handles sending JSON responses, logging access and reading request body.
 */
class Response {

    /**
     * Logger instance used for access logging.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Callable used to read raw request body.
     *
     * @var callable
     */
    private $inputReader;

    /**
     * Create a new Response instance.
     *
     * @param Logger|null $logger Optional Logger instance. If null, Logger::getInstance() is used.
     * @param callable|null $inputReader Optional callable returning raw request body as string.
     */
    public function __construct(?Logger $logger = null, ?callable $inputReader = null) {
        $this->logger = $logger ?? Logger::getInstance();
        $this->inputReader = $inputReader ?? function(): string { return file_get_contents('php://input') ?: ''; };
    }

    /**
     * Send a JSON response, log the access and exit.
     *
     * @param mixed $data Data to JSON-encode and send.
     * @param int $status HTTP status code to send.
     * @param array $extraHeaders Additional headers to send (associative array header => value).
     * @param string $outcome Outcome for logging ('ALLOW'|'DENY').
     * @param string $reason Reason code for logging (e.g. 'OK', 'NOT_FOUND').
     * @param string|null $keyUsed API key used (optional, for logging).
     * @return void
     */
    public function sendJson(mixed $data, int $status = 200, array $extraHeaders = [], string $outcome = 'ALLOW', string $reason = 'OK', ?string $keyUsed = null): void {
        foreach ($extraHeaders as $k => $v) header("$k: $v");
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->logger->logAccess($status, $outcome, $reason, $keyUsed);
        exit;
    }

    /**
     * Send a 404 JSON response and log DENY.
     *
     * @param string|null $keyUsed API key used (optional, for logging).
     * @param string $reason Reason code (default 'NOT_FOUND').
     * @return void
     */
    public function notFound(?string $keyUsed = null, string $reason = 'NOT_FOUND'): void {
        $this->sendJson(['error' => 'Not found'], 404, [], 'DENY', $reason, $keyUsed);
    }

    /**
     * Read and decode JSON request body.
     *
     * @return array Decoded JSON as associative array, or empty array on invalid/missing body.
     */
    public function readJsonBody(): array {
        $raw = call_user_func($this->inputReader) ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}