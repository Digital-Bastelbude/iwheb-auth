<?php
declare(strict_types=1);

namespace iwhebAPI\SessionManagement;

use PhpParser\Node\Stmt\Else_;

/**
 * SessionManagerConfigFactory
 *
 * Factory class to load session management configuration.
 * Loads API keys exclusively from .secrets.php and base configuration
 * (rate_limit defaults) from config.json.
 */
class SessionManagerConfigFactory
{
    private array $config = [];

    /**
     * Constructor
     * @param string|null $baseDir Optional base directory path
     * @param array|null $config Optional configuration array
     * @param string|null $secretsPath Optional path to secrets file
     */
    public function __construct(?string $baseDir = null, ?array $config = [], ?string $secretsPath = '') {

        // Use project root as base directory (4 levels up from src/SessionManagement/)
        if ($baseDir === null) {
            $baseDir = dirname(__DIR__, 3);
        }

        // Load secrets if path provided or use default
        if (strlen($secretsPath) > 0 && file_exists($secretsPath)) {
            $API_KEYS = [];
            include $secretsPath;
        } elseif (strlen($baseDir) > 0) {
            $defaultSecretsPath = $baseDir . '/config/.secrets.php';
            if (file_exists($defaultSecretsPath)) {
                $API_KEYS = [];
                include $defaultSecretsPath;
            } else {
                throw new \RuntimeException('Secrets file not found');
            }
        } else {
            throw new \RuntimeException('BASE_DIR is not defined');
        }

        // Load configuration
        if (count($config) > 0) {
            $this->config = $config;
        } else {
            $this->config = $this->loadDefaultConfig($API_KEYS ?? []);
        }

        
    }

    /**
     * Load default configuration
     * @param array $apiKeys API keys loaded from secrets file
     * @return array Configuration array
     */
    private function loadDefaultConfig(array $apiKeys = []): array
    {
       // Load base config from config.json (only for rate_limit defaults)
        $baseConfig = [
            'sessionmanagement' => [
                'rate_limit' => ['default' => ['window_seconds' => 60, 'max_requests' => 60]],
                'keys' => []
            ]
        ];

        // try to load rate limits from config file
        $configFile = getenv('CONFIG_FILE');
        if (!$configFile) {
            // Default to project root config/config.json
            $configFile = dirname(__DIR__, 3) . '/config/config.json';
        }

        if (file_exists($configFile)) {
            $raw = file_get_contents($configFile) ?: '';
            $cfg = json_decode($raw, true);
            if (is_array($cfg) && isset($cfg['sessionmanagement']) && isset($cfg['sessionmanagement']['rate_limit'])) {
                $baseConfig['sessionmanagement']['rate_limit'] = $cfg['sessionmanagement']['rate_limit'];
            }
        }
        
        // Normalize rate limit defaults
        $baseConfig['sessionmanagement']['rate_limit']['default']['window_seconds'] = (int)($baseConfig['sessionmanagement']['rate_limit']['default']['window_seconds'] ?? 60);
        $baseConfig['sessionmanagement']['rate_limit']['default']['max_requests']   = (int)($baseConfig['sessionmanagement']['rate_limit']['default']['max_requests'] ?? 60);

        // Load API keys from passed parameter (from .secrets.php)
        if (!empty($apiKeys) && is_array($apiKeys)) {
            foreach ($apiKeys as $key => $config) {
                // Convert .secrets.php format to config format
                $keyConfig = [];
                
                // Map routes (if available)
                if (isset($config['routes']) && is_array($config['routes'])) {
                    $keyConfig['routes'] = $config['routes'];
                }
                
                // Map rate_limit (if available)
                if (isset($config['sessionmanagement']['rate_limit']) && is_array($config['sessionmanagement']['rate_limit'])) {
                    $keyConfig['rate_limit'] = $config['sessionmanagement']['rate_limit'];
                }
                
                // Map name (if available)
                if (isset($config['name'])) {
                    $keyConfig['name'] = $config['name'];
                }
                
                // Add to config
                $baseConfig['sessionmanagement']['keys'][$key] = $keyConfig;
            }
        }
        
        return $baseConfig;
    }

    /**
     * Get the loaded configuration
     * @return array Configuration array
     * @throws \RuntimeException if configuration is not loaded
     */
    public function getConfig(): array
    {
        if (\count($this->config) === 0) {
            throw new \RuntimeException('Configuration not loaded');
        }

        return $this->config;
    }
}