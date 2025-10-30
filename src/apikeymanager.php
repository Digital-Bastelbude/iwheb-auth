<?php
/**
 * API Key Manager
 * 
 * Handles API key validation and permission checking.
 */

require_once __DIR__ . '/exceptions.php';

class ApiKeyManager {
    /** @var array API keys configuration (loaded from config/.secrets.php) */
    private array $apiKeys;

    /**
     * Constructor
     * 
     * @param array $apiKeys Array of API keys with their configurations
     */
    public function __construct(array $apiKeys) {
        $this->apiKeys = $apiKeys;
    }

    /**
     * Validate an API key
     * 
     * @param string $apiKey The API key to validate
     * @return bool True if valid, false otherwise
     */
    public function isValidApiKey(string $apiKey): bool {
        return isset($this->apiKeys[$apiKey]);
    }

    /**
     * Get API key configuration
     * 
     * @param string $apiKey The API key
     * @return array|null Configuration array or null if not found
     */
    public function getApiKeyConfig(string $apiKey): ?array {
        return $this->apiKeys[$apiKey] ?? null;
    }

    /**
     * Check if an API key has a specific permission
     * 
     * @param string $apiKey The API key
     * @param string $permission The permission to check (e.g., 'user_info', 'user_token')
     * @return bool True if permission granted, false otherwise
     */
    public function hasPermission(string $apiKey, string $permission): bool {
        $config = $this->getApiKeyConfig($apiKey);
        
        if (!$config) {
            return false;
        }
        
        $permissions = $config['permissions'] ?? [];
        return in_array($permission, $permissions, true);
    }

    /**
     * Check if an API key can access a specific route
     * 
     * Default routes (always allowed):
     * - /login
     * - /validate/{session_id}
     * - /session/check/{session_id}
     * - /session/touch/{session_id}
     * - /session/logout/{session_id}
     * 
     * Permission-based routes:
     * - /user/{session_id}/info: requires 'user_info' permission
     * - /user/{session_id}/token: requires 'user_token' permission
     * 
     * @param string $apiKey The API key
     * @param string $path The request path
     * @return bool True if access allowed, false otherwise
     */
    public function canAccessRoute(string $apiKey, string $path): bool {
        if (!$this->isValidApiKey($apiKey)) {
            return false;
        }

        // Default routes (always allowed for valid API keys)
        $defaultRoutes = [
            '#^/login$#',
            '#^/validate/[a-z0-9]+$#',
            '#^/session/check/[a-z0-9]+$#',
            '#^/session/touch/[a-z0-9]+$#',
            '#^/session/logout/[a-z0-9]+$#'
        ];

        foreach ($defaultRoutes as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        // Permission-based routes
        if (preg_match('#^/user/[a-z0-9]+/info$#', $path)) {
            return $this->hasPermission($apiKey, 'user_info');
        }

        if (preg_match('#^/user/[a-z0-9]+/token$#', $path)) {
            return $this->hasPermission($apiKey, 'user_token');
        }

        // Unknown route, deny by default
        return false;
    }

    /**
     * Extract API key from request headers
     * 
     * Checks for X-API-Key header
     * 
     * @return string|null The API key or null if not found
     */
    public static function extractApiKeyFromRequest(): ?string {
        // Check X-API-Key header
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return $_SERVER['HTTP_X_API_KEY'];
        }

        // Alternative: check Authorization header with "ApiKey " prefix
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
            if (preg_match('/^ApiKey\s+(.+)$/i', $auth, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Get the name of an API key
     * 
     * @param string $apiKey The API key
     * @return string|null The name or null if not found
     */
    public function getApiKeyName(string $apiKey): ?string {
        $config = $this->getApiKeyConfig($apiKey);
        return $config['name'] ?? null;
    }
}
