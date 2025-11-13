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
     * Path to the log directory.
     *
     * @var string
     */
    private string $logPath;

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
     * @param string $logPath Path to the log directory (or file for testing).
     * @param array|null $server Optional $_SERVER snapshot; defaults to global $_SERVER.
     * @param array|null $get Optional $_GET snapshot; defaults to global $_GET.
     * @param callable|null $inputReader Optional reader for request body; defaults to file_get_contents('php://input').
     */
    private function __construct(string $logPath, ?array $server = null, ?array $get = null, ?callable $inputReader = null) {
        $this->logPath = $logPath;
        $this->server = $server ?? ($_SERVER ?? []);
        $this->get = $get ?? ($_GET ?? []);
        $this->inputReader = $inputReader ?? function(): string { return file_get_contents('php://input') ?: ''; };
    }

    /**
     * Obtain a shared Logger instance.
     *
     * If an instance was already created it is returned; otherwise a new instance
     * is created using provided arguments or default path.
     *
     * @param string|null $logPath Optional path to log directory or file. If null, uses default logs directory.
     * @param array|null $server Optional server snapshot for testing.
     * @param array|null $get Optional get snapshot for testing.
     * @param callable|null $inputReader Optional input reader for testing.
     * @return Logger Shared Logger instance.
     */
    public static function getInstance(?string $logPath = null, ?array $server = null, ?array $get = null, ?callable $inputReader = null): Logger {
        if (self::$instance instanceof Logger) {
            return self::$instance;
        }

        // Use default path if none provided
        if (!isset($logPath)) {
            $logPath = dirname(__DIR__, 2) . '/logs/';
        }

        self::$instance = new Logger($logPath, $server, $get, $inputReader);
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
     * Append one JSON line to the log file.
     *
     * @param string $level Log level (e.g. INFO, WARNING, ERROR, ...).
     * @param string $outcome 'ALLOW' or 'DENY'.
     * @param string $reason Reason code (e.g. OK, NO_KEY, RATE_LIMIT, ...).
     * @param string $logFilename Log filename without file ending (default: "sessionmanager").
     * @param array|null $data Additional data to log (optional).
     * @param string|null $keyUsed API key used or null.
     * @param string|null $customPath Optional custom full file path (for tests).
     * @return void
     */
    public function log(string $level, string $outcome, string $reason, string $logFilename = "sessionmanager", ?array $data = [], ?string $keyUsed = null, ?string $customPath = null): void {
        $entry = [
            'ts'     => gmdate('c'),
            'level' => $level,
            'outcome'=> $outcome,
            'key'    => $keyUsed ?: '(none)',
            'reason' => $reason,
            'data'   => isset($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : "",
        ];
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL;

        // Use custom path if provided, otherwise construct from logPath
        if ($customPath) {
            $logFile = $customPath;
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        } else {
            $logFile = $this->logPath . $logFilename . ".log";
            if (!is_dir($this->logPath)) @mkdir($this->logPath, 0775, true);
        }

        $fp = fopen($logFile, 'a');
        if ($fp) {
            flock($fp, LOCK_EX);
            fwrite($fp, $line);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Log an error message.
     * @param string $message.
     * @param array|null $data Additional data to log (optional).
     * @param string|null $customPath Optional custom full file path (for tests).
     */
    public function logError(string $message, ?array $data = [], ?string $customPath = null): void {
        $this->log("ERROR", "", $message, "error", $data, null, $customPath);
    }

    /**
     * Log a warning message.
     * @param string $message.
     * @param array|null $data Additional data to log (optional).
     * @param string|null $customPath Optional custom full file path (for tests).
     */
    public function logWarning(string $message, ?array $data = [], ?string $customPath = null): void {
        $this->log("WARNING", "", $message, "warning", $data, null, $customPath);
    }


    /**
     * Log a debug message.
     * @param string $message.
     * @param string|null $logFilename Log filename without file ending (default: "debug").
     * @param array|null $data Additional data to log (optional).
     * @param string|null $customPath Optional custom full file path (for tests).
     */
    public function logDebug(string $message, ?string $logFilename = "debug", ?array $data = [], ?string $customPath = null): void {
        $this->log("DEBUG", "", $message, $logFilename, $data, null, $customPath);
    }

    /**
     * Log an info message.
     * @param string $message.
     * @param string|null $logFilename Log filename without file ending (default: "info").
     * @param array|null $data Additional data to log (optional).
     * @param string|null $customPath Optional custom full file path (for tests).
     */
    public function logInfo(string $message, ?string $logFilename = "info", ?array $data = [], ?string $customPath = null): void {
        $this->log("INFO", "", $message, $logFilename, $data, null, $customPath);
    }

    /**
     * Log an access event.
     * @param string $outcome 'ALLOW' or 'DENY'.
     * @param string $reason Reason code (e.g. OK, NO_KEY, RATE_LIMIT, ...).
     * @param string|null $keyUsed API key used or null.
     * @param string|null $customPath Optional custom full file path (for tests).
     */
    public function logAccess(string $outcome, string $reason, ?string $keyUsed, ?string $customPath = null): void {
        $this->log("ACCESS", $outcome, $reason, "access", [], $keyUsed, $customPath);
    }

    /**
     * Log a response event.
     * @param string $outcome 'ALLOW' or 'DENY'.
     * @param string $reason Reason code (e.g. OK, NO_KEY, RATE_LIMIT, ...).
     * @param array|null $data Additional data to log (optional).
     * @param string|null $keyUsed API key used or null.
     * @param string|null $customPath Optional custom full file path (for tests).
     */
    public function logResponse(int $status, string $outcome, string $reason, ?array $data = [], ?string $keyUsed = null, ?string $customPath = null): void {
        $this->log(strval($status), $outcome, $reason, "response", $data, $keyUsed, $customPath);
    }

    /**
     * Log a database event.
     * @param string $outcome 'ALLOW' or 'DENY'.
     * @param string $reason Reason code (e.g. OK, NO_KEY, RATE_LIMIT, ...).
     * @param array|null $data Additional data to log (optional).
     * @param string|null $customPath Optional custom full file path (for tests).
     */
    public function logDB(string $outcome, string $reason, ?array $data = [], ?string $customPath = null): void {
        $this->log("DB", $outcome, $reason, "db", $data, null, $customPath);
    }
    
}