<?php

/**
 * load_config
 *
 * Load application config with API keys exclusively from .secrets.php.
 * Base configuration (rate_limit defaults) comes from config.json.
 *
 * @return array Config array with keys 'rate_limit' and 'keys'.
 */
function load_config(): array {
    // Load base config from config.json (only for rate_limit defaults)
    $baseConfig = [
        'rate_limit' => ['default' => ['window_seconds' => 60, 'max_requests' => 60]],
        'keys' => []
    ];
    
    if (file_exists(CONFIG_FILE)) {
        $raw = file_get_contents(CONFIG_FILE) ?: '';
        $cfg = json_decode($raw, true);
        if (is_array($cfg) && isset($cfg['rate_limit'])) {
            $baseConfig['rate_limit'] = $cfg['rate_limit'];
        }
    }
    
    // Normalize rate limit defaults
    $baseConfig['rate_limit']['default']['window_seconds'] = (int)($baseConfig['rate_limit']['default']['window_seconds'] ?? 60);
    $baseConfig['rate_limit']['default']['max_requests']   = (int)($baseConfig['rate_limit']['default']['max_requests'] ?? 60);
    
    // Load API keys ONLY from .secrets.php
    global $API_KEYS;
    if (isset($API_KEYS) && is_array($API_KEYS)) {
        foreach ($API_KEYS as $key => $config) {
            // Convert .secrets.php format to config format
            $keyConfig = [];
            
            // Map routes (if available)
            if (isset($config['routes']) && is_array($config['routes'])) {
                $keyConfig['routes'] = $config['routes'];
            }
            
            // Map scopes (if available)
            if (isset($config['scopes']) && is_array($config['scopes'])) {
                $keyConfig['scopes'] = $config['scopes'];
            }
            
            // Map rate_limit (if available)
            if (isset($config['rate_limit']) && is_array($config['rate_limit'])) {
                $keyConfig['rate_limit'] = $config['rate_limit'];
            }
            
            // Map name (if available)
            if (isset($config['name'])) {
                $keyConfig['name'] = $config['name'];
            }
            
            // Add to config
            $baseConfig['keys'][$key] = $keyConfig;
        }
    }
    
    return $baseConfig;
}
