<?php
declare(strict_types=1);

namespace iwhebAPI\SessionManagement\Auth;

/**
 * API Key Manager
 * 
 * Lightweight helper for API key extraction and permission lookups.
 * Authorization is handled by the Authorizer class.
 */

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
     * Used by controllers for fine-grained permission checks.
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
    
    /**
     * Check if an API key exists and is valid
     * 
     * @param string $apiKey The API key to check
     * @return bool True if key exists, false otherwise
     */
    public function isValidApiKey(string $apiKey): bool {
        return isset($this->apiKeys[$apiKey]);
    }
}
