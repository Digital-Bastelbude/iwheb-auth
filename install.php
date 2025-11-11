<?php
/**
 * Installation script for iWheb Session Manager
 * Run this from your project root after copying src/SessionManagement/
 */

echo "🚀 Installing iWheb Session Manager...\n\n";

$projectRoot = __DIR__;

// ============================================================================
// 1. UPDATE COMPOSER AUTOLOAD (NON-DESTRUCTIVE)
// ============================================================================

$composerPath = $projectRoot . '/composer.json';
if (file_exists($composerPath)) {
    $composer = json_decode(file_get_contents($composerPath), true);
    $updated = false;
    
    // Initialize autoload structure if missing
    if (!isset($composer['autoload'])) {
        $composer['autoload'] = [];
    }
    if (!isset($composer['autoload']['psr-4'])) {
        $composer['autoload']['psr-4'] = [];
    }
    
    // Add SessionManagement namespace if not exists
    if (!isset($composer['autoload']['psr-4']['iwhebAPI\\SessionManagement\\'])) {
        $composer['autoload']['psr-4']['iwhebAPI\\SessionManagement\\'] = 'src/SessionManagement/';
        $updated = true;
        echo "✅ Added SessionManagement PSR-4 namespace\n";
    } else {
        echo "ℹ️  SessionManagement PSR-4 namespace already exists\n";
    }
    
    // Add logging.php to files if not exists
    if (!isset($composer['autoload']['files'])) {
        $composer['autoload']['files'] = [];
    }
    if (!in_array('src/SessionManagement/logging.php', $composer['autoload']['files'])) {
        $composer['autoload']['files'][] = 'src/SessionManagement/logging.php';
        $updated = true;
        echo "✅ Added logging.php to autoload files\n";
    } else {
        echo "ℹ️  logging.php already in autoload files\n";
    }
    
    if ($updated) {
        file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "✅ Composer.json updated\n";
    }
} else {
    echo "❌ No composer.json found in project root\n";
    exit(1);
}

// ============================================================================
// 2. CREATE/UPDATE CONFIG.JSON (NON-DESTRUCTIVE)
// ============================================================================

$configPath = $projectRoot . '/config/config.json';
$configDir = dirname($configPath);

// Create config directory if missing
if (!is_dir($configDir)) {
    mkdir($configDir, 0755, true);
    echo "✅ Created config directory\n";
}

$defaultConfig = [
    'sessionsmanagement' => [
        'rate_limit' => [
            'default' => [
                'window_seconds' => 60,
                'max_requests' => 100
            ]
        ]
    ]
];

if (file_exists($configPath)) {
    // Load existing config
    $existingConfig = json_decode(file_get_contents($configPath), true);
    if ($existingConfig === null) {
        $existingConfig = [];
    }
    
    $updated = false;
    
    // Merge rate_limit defaults if not exists
    if (!isset($existingConfig['sessionsmanagement'])) {
        $existingConfig['sessionsmanagement'] = $defaultConfig['sessionsmanagement'];
        $updated = true;
    } elseif (!isset($existingConfig['sessionsmanagement']['rate_limit'])) {
        $existingConfig['sessionsmanagement']['rate_limit'] = $defaultConfig['sessionsmanagement']['rate_limit'];
        $updated = true;
    } elseif (!isset($existingConfig['sessionsmanagement']['rate_limit']['default'])) {
        $existingConfig['sessionsmanagement']['rate_limit']['default'] = $defaultConfig['sessionsmanagement']['rate_limit']['default'];
        $updated = true;
    }
    
    if ($updated) {
        file_put_contents($configPath, json_encode($existingConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "✅ Updated config.json with SessionManagement defaults\n";
    } else {
        echo "ℹ️  config.json already has SessionManagement configuration\n";
    }
} else {
    // Create new config file
    file_put_contents($configPath, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "✅ Created config.json with SessionManagement defaults\n";
}

// ============================================================================
// 3. CREATE/UPDATE .SECRETS.PHP (NON-DESTRUCTIVE)
// ============================================================================

$secretsPath = $projectRoot . '/config/.secrets.php';

if (!file_exists($secretsPath)) {
    // Generate sample API key
    require_once $projectRoot . '/src/SessionManagement/Auth/KeyGenerator.php';
    $sampleApiKey = generateApiKey(32);
    
    $secretsContent = <<<PHP
<?php
/**
 * Secrets Configuration
 * 
 * IMPORTANT: This file contains sensitive data and should NEVER be committed to version control.
 * Generate new API keys using: php src/SessionManagement/keygenerator.php
 */

// ============================================================================
// API KEYS CONFIGURATION
// ============================================================================
\$API_KEYS = [
    '{$sampleApiKey}' => [
        'name' => 'Default Application',
        'permissions' => ['user_info', 'user_token', 'delegate'],
        'routes' => [
            // TODO: Specify allowed routes
        ],
        'rate_limit' => [
            'window_seconds' => 60,
            'max_requests' => 100
        ]
    ]
];
PHP;
    
    file_put_contents($secretsPath, $secretsContent);
    chmod($secretsPath, 0600);
    echo "✅ Created .secrets.php with sample API key\n";
    echo "🔑 Sample API Key: {$sampleApiKey}\n";
} else {
    echo "ℹ️  .secrets.php already exists, keeping existing configuration\n";
    
    // Check if $API_KEYS variable exists
    $secretsContent = file_get_contents($secretsPath);
    if (strpos($secretsContent, '$API_KEYS') === false) {
        echo "⚠️  WARNING: .secrets.php exists but \$API_KEYS variable not found\n";
        echo "   Please add \$API_KEYS array manually or regenerate the file\n";
    }
}

// ============================================================================
// 4. CREATE REQUIRED DIRECTORIES
// ============================================================================

$requiredDirs = [
    $projectRoot . '/storage',
    $projectRoot . '/storage/data',
    $projectRoot . '/storage/logs',
    $projectRoot . '/storage/ratelimit'
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "✅ Created directory: " . basename($dir) . "/\n";
    }
}

// Create .gitkeep files
$gitkeepFiles = [
    $projectRoot . '/storage/data/.gitkeep',
    $projectRoot . '/storage/logs/.gitkeep', 
    $projectRoot . '/storage/ratelimit/.gitkeep'
];

foreach ($gitkeepFiles as $file) {
    if (!file_exists($file)) {
        touch($file);
    }
}

// ============================================================================
// 5. FINAL STEPS
// ============================================================================

echo "\n🎉 Installation completed!\n\n";
echo "Next steps:\n";
echo "1. Run: composer dump-autoload\n";
echo "2. Review config/config.json for your rate limit settings\n";
echo "3. Review config/.secrets.php for your API keys\n";
echo "4. Generate additional API keys with: php src/SessionManagement/keygenerator.php\n\n";

echo "Usage example:\n";
echo "<?php\n";
echo "use iwhebAPI\\SessionManagement\\Database\\Database;\n";
echo "\$db = Database::fromEnv();\n";
echo "\$session = \$db->createSession('your-api-key');\n\n";