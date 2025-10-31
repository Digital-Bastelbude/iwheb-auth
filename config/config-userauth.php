<?php

/**
 * load_config
 *
 * Load application config from CONFIG_FILE and normalize defaults used by the app.
 *
 * @return array Config array with keys 'rate_limit' and 'keys'.
 */
function load_config(): array {
    if (!file_exists(CONFIG_FILE)) {
        return [
            'rate_limit' => ['default' => ['window_seconds' => 60, 'max_requests' => 60]],
            'keys' => []
        ];
    }
    $raw = file_get_contents(CONFIG_FILE) ?: '';
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) {
        return [
            'rate_limit' => ['default' => ['window_seconds' => 60, 'max_requests' => 60]],
            'keys' => []
        ];
    }
    $cfg['rate_limit']['default']['window_seconds'] = (int)($cfg['rate_limit']['default']['window_seconds'] ?? 60);
    $cfg['rate_limit']['default']['max_requests']   = (int)($cfg['rate_limit']['default']['max_requests'] ?? 60);
    $cfg['keys'] = is_array($cfg['keys'] ?? null) ? $cfg['keys'] : [];
    return $cfg;
}
