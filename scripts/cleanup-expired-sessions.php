#!/usr/bin/env php
<?php
/**
 * Cleanup expired sessions
 * 
 * This maintenance script deletes all sessions that have expired.
 * 
 * Example crontab entry (every 5 minutes):
 * Star-slash-5 * * * * cd /home/hannes/Workspace/web/iwheb-auth && php scripts/cleanup-expired-sessions.php >> /var/log/iwheb-auth-cleanup.log 2>&1
 * 
 * Replace "Star-slash-5" with: asterisk + forward-slash + 5
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables from config/.secrets.php
$secretsFile = __DIR__ . '/../config/.secrets.php';
if (!file_exists($secretsFile)) {
    echo "[ERROR] Configuration file not found: {$secretsFile}\n";
    exit(1);
}
require_once $secretsFile;

use iwhebAPI\UserAuth\Database\Database;

try {
    // Instantiate Database from environment
    $db = Database::fromEnv();
    
    $deleted = $db->deleteExpiredSessions();
    
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] Cleanup: Deleted {$deleted} expired sessions.\n";
} catch (\Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

