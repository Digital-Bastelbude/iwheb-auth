<?php

/**
 * Logger
 *
 * File-based JSON line logger and helpers for sending JSON responses and reading request body.
 * All required values are injected via constructor and stored as private attributes.
 *
 * Use Logger::getInstance(...) to obtain a shared instance (no global functions).
 */
class Logger {
    /**
     * Shared singleton instance.
     *
     * @var Logger|null
     */
    private static ?Logger $instance = null;

    /**
     * Path to the log file.
     *
     * @var string
     */
    private string $logFile;

    /**
     * Server superglobal snapshot (used for method, URI, headers, IP).
     *
     * @var array
     */
    private array $server;

    /**
     * GET parameters snapshot.
     *
     * @var array
     */
    private array $get;

    /**
     * Callable used to read raw request body. Should return a string.
     *
     * @var callable
     */
    private $inputReader;

    /**
     * Private constructor to enforce singleton usage.
     *
     * @param string $logFile Path to the log file.
     * @param array|null $server Optional $_SERVER snapshot; defaults to global $_SERVER.
     * @param array|null $get Optional $_GET snapshot; defaults to global $_GET.
     * @param callable|null $inputReader Optional reader for request body; defaults to file_get_contents('php://input').
     */
    private function __construct(string $logFile, ?array $server = null, ?array $get = null, ?callable $inputReader = null) {
        $this->logFile = $logFile;
        $this->server = $server ?? ($_SERVER ?? []);
        $this->get = $get ?? ($_GET ?? []);
        $this->inputReader = $inputReader ?? function(): string { return file_get_contents('php://input') ?: ''; };
    }

    /**
     * Obtain a shared Logger instance.
     *
     * If an instance was already created it is returned; otherwise a new instance
     * is created using provided arguments or LOG_FILE constant / default path.
     *
     * @param string|null $logFile Optional path to log file. If null, uses LOG_FILE or __DIR__.'/logs/access.log'.
     * @param array|null $server Optional server snapshot for testing.
     * @param array|null $get Optional get snapshot for testing.
     * @param callable|null $inputReader Optional input reader for testing.
     * @return Logger Shared Logger instance.
     */
    public static function getInstance(?string $logFile = null, ?array $server = null, ?array $get = null, ?callable $inputReader = null): Logger {
        if (self::$instance instanceof Logger) {
            return self::$instance;
        }
        $logFile = $logFile ?? (defined('LOG_FILE') ? LOG_FILE : (__DIR__ . '/logs/access.log'));
        self::$instance = new Logger($logFile, $server, $get, $inputReader);
        return self::$instance;
    }

    /**
     * Reset the shared instance (useful for tests).
     *
     * @return void
     */
    public static function resetInstance(): void {
        self::$instance = null;
    }

    /**
     * Prevent cloning of the singleton.
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the singleton.
     */
    /**
     * Prevent unserializing of the singleton.
     *
     * Must be public to satisfy PHP magic method visibility requirements.
     */
    public function __wakeup(): void {}

    /**
     * Determine client IP (prefers X-Forwarded-For).
     *
     * @return string Client IP address.
     */
    private function clientIp(): string {
        $ip = $this->server['HTTP_X_FORWARDED_FOR'] ?? $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($ip, ',') !== false) {
            $parts = explode(',', $ip);
            $ip = trim($parts[0]);
        }
        return $ip;
    }

    /**
     * Append one JSON line to the log file.
     *
     * @param int $status HTTP status code.
     * @param string $outcome 'ALLOW' or 'DENY'.
     * @param string $reason Reason code (e.g. OK, NO_KEY, RATE_LIMIT, ...).
     * @param string|null $keyUsed API key used or null.
     * @return void
     */
    public function logAccess(int $status, string $outcome, string $reason, ?string $keyUsed): void {
        $entry = [
            'ts'     => gmdate('c'),
            'ip'     => $this->clientIp(),
            'method' => $this->server['REQUEST_METHOD'] ?? 'GET',
            'path'   => parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH),
            'status' => $status,
            'outcome'=> $outcome,
            'key'    => $keyUsed ?: '(none)',
            'reason' => $reason
        ];
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $fp = fopen($this->logFile, 'a');
        if ($fp) {
            flock($fp, LOCK_EX);
            fwrite($fp, $line);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    
}