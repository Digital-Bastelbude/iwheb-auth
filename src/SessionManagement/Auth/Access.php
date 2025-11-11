<?php
declare(strict_types=1);

namespace iwhebAPI\SessionManagement\Auth;


/**
 * ApiKeyHelper
 *
 * Helper for extracting API key from request headers/params and utilities
 * for matching route rules.
 */
class ApiKeyHelper {
    /**
     * Collect HTTP request headers in a portable way.
     *
     * @return array Associative array of header name => value.
     */
    private function collectHeaders(): array {
        if (function_exists('getallheaders')) return getallheaders();
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $headers[$name] = $v;
            }
        }
        return $headers;
    }

    /**
     * Retrieve API key from headers or query parameter.
     *
     * Looks for "X-Api-Key", "Authorization: ApiKey <key>" or ?api_key=...
     *
     * @return string|null API key or null when not present.
     */
    public function getApiKey(): ?string {
        $headers = $this->collectHeaders();
        $key = null;
        foreach ($headers as $k => $v) {
            $lk = strtolower($k);
            if ($lk === 'x-api-key') { $key = trim((string)$v); break; }
            if ($lk === 'authorization' && preg_match('/^ApiKey\s+(.+)$/i', trim((string)$v), $m)) { $key = trim($m[1]); break; }
        }
        if (!$key && isset($_GET['api_key'])) $key = trim((string)$_GET['api_key']);
        return $key ?: null;
    }

    /**
     * Match a route rule like "GET:/items/{id}" against method+path.
     *
     * @param string $rule Rule string "METHOD:/path/with/{params}".
     * @param string $method HTTP method to match.
     * @param string $path Request path to match.
     * @return bool True if rule matches.
     */
    public function routeMatches(string $rule, string $method, string $path): bool {
        $parts = explode(':', $rule, 2);
        if (count($parts) !== 2) return false;
        [$rm, $rp] = [$parts[0], $parts[1]];
        if (strtoupper($rm) !== strtoupper($method)) return false;
        $pattern = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', function($m) {
            return ($m[1] === 'id') ? '(\\d+)' : '([^/]+)';
        }, $rp);
        $pattern = '#^' . str_replace('/', '\/', $pattern) . '$#';
        return (bool)preg_match($pattern, $path);
    }
}

/**
 * RateLimiter
 *
 * Simple file-based sliding window counter per key. Directory is provided
 * via constructor and kept private.
 */
class RateLimiter {
    /**
     * Directory used to store per-key JSON files.
     *
     * @var string
     */
    private string $dir;

    /**
     * Constructor.
     *
     * @param string $dir Directory path for rate-limit state files.
     */
    public function __construct(string $dir) {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) @mkdir($this->dir, 0775, true);
    }

    /**
     * Check and update rate limit for a given key.
     *
     * Uses a simple fixed window counter persisted per key.
     *
     * @param string $key Unique identifier for the client (e.g. API key).
     * @param int $windowSec Window size in seconds.
     * @param int $maxRequests Maximum allowed requests in the window.
     * @return bool True if the request is allowed, false when limit exceeded.
     */
    public function checkRateLimit(string $key, int $windowSec, int $maxRequests): bool {
        $file = $this->dir . '/' . sha1($key) . '.json';
        $now = time();
        
        // Load current state
        $state = $this->loadState($file, $now);
        
        // Reset window if expired
        if ($now - $state['window_start'] >= $windowSec) {
            $state = ['window_start' => $now, 'count' => 0];
        }
        
        // Check limit
        if ($state['count'] >= $maxRequests) {
            $this->saveState($file, $state);
            return false;
        }
        
        // Increment and save
        $state['count']++;
        $this->saveState($file, $state);
        return true;
    }
    
    /**
     * Load rate limit state from file.
     *
     * @param string $file Path to state file
     * @param int $defaultTimestamp Default timestamp for new state
     * @return array State array with 'window_start' and 'count'
     */
    private function loadState(string $file, int $defaultTimestamp): array {
        if (!file_exists($file)) {
            return ['window_start' => $defaultTimestamp, 'count' => 0];
        }
        
        $json = @file_get_contents($file);
        if ($json === false || $json === '') {
            return ['window_start' => $defaultTimestamp, 'count' => 0];
        }
        
        $state = json_decode($json, true);
        if (!is_array($state) || !isset($state['window_start'], $state['count'])) {
            return ['window_start' => $defaultTimestamp, 'count' => 0];
        }
        
        return $state;
    }
    
    /**
     * Save rate limit state to file atomically.
     *
     * @param string $file Path to state file
     * @param array $state State array with 'window_start' and 'count'
     */
    private function saveState(string $file, array $state): void {
        @file_put_contents($file, json_encode($state), LOCK_EX);
    }
}

/**
 * AuthorizationException
 *
 * Thrown by Authorizer when authorization fails. Contains an optional
 * API key and a reason code.
 */
class AuthorizationException extends \Exception {
    /** @var string|null API key that was used (may be null) */
    public ?string $key;
    /** @var string Reason code (e.g. NO_KEY, INVALID_KEY, RATE_LIMIT) */
    public string $reason;

    public function __construct(?string $key, string $reason, string $message = '') {
        parent::__construct($message ?: $reason);
        $this->key = $key;
        $this->reason = $reason;
    }
}

/**
 * Authorizer
 *
 * Performs API key validation, permission checks (routes) and rate limiting.
 * Configuration array must be provided via constructor; ApiKeyHelper and RateLimiter
 * can be injected or are created from config defaults.
 */
class Authorizer {
    /**
     * Application configuration array (must include 'keys' and optionally 'rate_limit').
     *
     * @var array
     */
    private array $config;

    /**
     * ApiKeyHelper instance used to extract key and evaluate route/method logic.
     *
     * @var ApiKeyHelper
     */
    private ApiKeyHelper $keyHelper;

    /**
     * RateLimiter instance used to enforce per-key limits.
     *
     * @var RateLimiter
     */
    private RateLimiter $rateLimiter;

    /**
     * Constructor.
     *
     * @param array $config Application config containing key definitions and defaults.
     * @param ApiKeyHelper|null $keyHelper Optional helper instance.
     * @param RateLimiter|null $rateLimiter Optional rate limiter instance.
     */
    public function __construct(array $config, ?ApiKeyHelper $keyHelper = null, ?RateLimiter $rateLimiter = null) {
        $this->config = $config;
        $this->keyHelper = $keyHelper ?? new ApiKeyHelper();
        if ($rateLimiter !== null) {
            $this->rateLimiter = $rateLimiter;
        } else {
            $rlDir = $this->config['rate_limit']['dir'] ?? '/storage/ratelimit';
            $this->rateLimiter = new RateLimiter($rlDir);
        }
    }

    /**
     * Authorize an API request.
     *
     * Validates the API key, checks route permissions, and enforces rate limits.
     *
     * @param string $method HTTP method (GET, POST, PUT, etc.)
     * @param string $path Request path (e.g., "/user/123/info")
     * @param string $key API key to authorize
     * @return array ['key' => string, 'def' => array] Key and its configuration
     * @throws AuthorizationException if authorization fails (NO_KEY, INVALID_KEY, NO_PERMISSION, RATE_LIMIT)
     */
    public function authorize(string $method, string $path, string $key): array {
        $cfg = $this->config;
        if (!$key) throw new AuthorizationException(null, 'NO_KEY');

        $kdef = $cfg['keys'][$key] ?? null;
        if (!$kdef || !is_array($kdef)) throw new AuthorizationException($key, 'INVALID_KEY');

        // Check permission: routes first
        $allowed = false;
        $routes = $kdef['routes'] ?? null;
        if (is_array($routes) && $routes) {
            foreach ($routes as $rule) {
                if (is_string($rule) && $this->keyHelper->routeMatches($rule, $method, $path)) { $allowed = true; break; }
            }
        }
    if (!$allowed) throw new AuthorizationException($key, 'NO_PERMISSION');

        // Rate limit
        $rl = $kdef['rate_limit'] ?? [];
        $w  = isset($rl['window_seconds']) ? (int)$rl['window_seconds'] : (int)($cfg['rate_limit']['default']['window_seconds'] ?? 60);
        $m  = isset($rl['max_requests'])   ? (int)$rl['max_requests']   : (int)($cfg['rate_limit']['default']['max_requests']   ?? 60);
        if ($w <= 0) $w = 60;
        if ($m <= 0) $m = 60;
    if (!$this->rateLimiter->checkRateLimit($key, $w, $m)) throw new AuthorizationException($key, 'RATE_LIMIT');

        return ['key' => $key, 'def' => $kdef];
    }
}