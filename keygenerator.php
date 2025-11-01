<?php
/**
 * Key Generator - Command Line Interface
 * 
 * Generate encryption keys and API keys with clear labeling.
 * This file provides the functions expected by the documentation.
 */

// Load the actual KeyGenerator functions
require_once __DIR__ . '/src/UserAuth/Auth/KeyGenerator.php';

/**
 * Generate an encryption key (32 bytes, base64 encoded)
 */
function generateEncryptionKey(): string {
    return 'base64:' . base64_encode(random_bytes(32));
}

/**
 * Display help information
 */
function showHelp(): void {
    echo "Key Generator - Generate secure keys for authentication\n\n";
    echo "Usage:\n";
    echo "  php keygenerator.php                    # Generate API key (32 chars)\n";
    echo "  php keygenerator.php api [length]       # Generate API key with optional length\n";
    echo "  php keygenerator.php encryption         # Generate encryption key\n";
    echo "  php keygenerator.php help               # Show this help\n\n";
    echo "Examples:\n";
    echo "  php keygenerator.php                    # API key: abc123...\n";
    echo "  php keygenerator.php api 64             # 64-char API key\n";
    echo "  php keygenerator.php encryption         # Encryption key: base64:...\n";
}

// If called directly from command line
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    $type = $argv[1] ?? 'api';
    
    switch ($type) {
        case 'help':
        case '-h':
        case '--help':
            showHelp();
            break;
            
        case 'encryption':
            $key = generateEncryptionKey();
            echo "Encryption Key (for config/.secrets.php ENCRYPTION_KEY):\n";
            echo $key . "\n\n";
            echo "Add to config/.secrets.php:\n";
            echo "putenv('ENCRYPTION_KEY=$key');\n";
            break;
            
        case 'api':
        default:
            $length = isset($argv[2]) ? (int)$argv[2] : 32;
            if ($length < 16) $length = 32;
            
            $key = generateApiKey($length);
            echo "API Key (for .secrets.php \$API_KEYS array):\n";
            echo $key . "\n\n";
            echo "Add to config/.secrets.php \$API_KEYS array:\n";
            echo "    '$key' => [\n";
            echo "        'name' => 'Your App Name',\n";
            echo "        'permissions' => ['user_info', 'user_token']\n";
            echo "    ],\n";
            break;
    }
}