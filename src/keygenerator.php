<?php
/**
 * API Key Generator
 * 
 * Provides functions to generate secure, URL-safe alphanumeric keys.
 */

/**
 * Generate a secure, URL-safe alphanumeric API key
 * 
 * @param int $length The length of the key to generate (default: 32)
 * @return string A URL-safe alphanumeric string
 */
function generateApiKey(int $length = 32): string {
    if ($length < 16) {
        throw new \InvalidArgumentException('Key length must be at least 16 characters');
    }
    
    // Generate random bytes (more bytes than needed for base64 conversion)
    $bytes = random_bytes((int)ceil($length * 3 / 4));
    
    // Convert to base64 and make URL-safe (no +, /, or =)
    $key = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    
    // Trim to exact length
    return substr($key, 0, $length);
}

/**
 * Generate multiple unique API keys
 * 
 * @param int $count Number of keys to generate
 * @param int $length Length of each key
 * @return array Array of unique API keys
 */
function generateApiKeys(int $count, int $length = 32): array {
    $keys = [];
    while (count($keys) < $count) {
        $key = generateApiKey($length);
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/**
 * Validate an API key format (URL-safe alphanumeric)
 * 
 * @param string $key The key to validate
 * @return bool True if valid, false otherwise
 */
function isValidApiKeyFormat(string $key): bool {
    // Check if key contains only URL-safe characters (alphanumeric, -, _)
    return preg_match('/^[a-zA-Z0-9_-]+$/', $key) === 1;
}
