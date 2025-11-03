#!/usr/bin/env php
<?php
/**
 * Cleanup duplicate sessions for same user/API-key combinations
 * 
 * This maintenance script decrypts all user tokens and removes duplicate
 * sessions where the same Webling user ID is logged in with the same API key
 * multiple times. Keeps only the most recent session.
 * 
 * Run manually when needed:
 * php scripts/cleanup-duplicate-sessions.php
 * 
 * Or via cron (e.g., daily at 3 AM):
 * 0 3 * * * cd /home/hannes/Workspace/web/iwheb-auth && php scripts/cleanup-duplicate-sessions.php >> /var/log/iwheb-auth-cleanup.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables from config/.secrets.php
$secretsFile = __DIR__ . '/../config/.secrets.php';
if (!file_exists($secretsFile)) {
    echo "[ERROR] Configuration file not found: {$secretsFile}\n";
    exit(1);
}
require_once $secretsFile;

use IwhebAPI\UserAuth\Database\{Database, UidEncryptor};

try {
    // Instantiate Database using getInstance() (reads from environment or uses default DATA_FILE)
    $db = Database::getInstance();
    
    // Initialize UID encryptor from environment
    $uidEncryptor = UidEncryptor::fromEnv();
    
    $deleted = $db->deleteDuplicateUserApiKeySessions($uidEncryptor);
    
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] Cleanup: Deleted {$deleted} duplicate sessions.\n";
} catch (\Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

