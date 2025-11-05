#!/usr/bin/env php
<?php
/**
 * Cleanup duplicate sessions for same user/API-key combinations
 * 
 * This maintenance script decrypts all user tokens and removes duplicate
 * sessions where the same Webling user ID is logged in with the same API key
 * multiple times. Keeps only the most recent session (by created_at timestamp).
 * 
 * Run manually when needed:
 * php scripts/cleanup-duplicate-sessions.php
 * 
 * Or via cron (e.g., daily at 3 AM):
 * 0 3 * * * cd /path/to/iwheb-auth && php scripts/cleanup-duplicate-sessions.php >> /var/log/iwheb-auth-cleanup.log 2>&1
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
    // Instantiate Database from environment
    $db = Database::fromEnv();
    
    // Initialize UID encryptor from environment
    $uidEncryptor = UidEncryptor::fromEnv();
    
    // Get all sessions with user tokens
    $pdo = getPdoConnection();
    $stmt = $pdo->query("
        SELECT session_id, user_token, api_key, created_at 
        FROM sessions 
        WHERE user_token IS NOT NULL
        ORDER BY created_at DESC
    ");
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "[INFO] Found " . count($sessions) . " sessions with user tokens.\n";
    
    // Group sessions by decrypted user ID + API key
    $seen = [];
    $toDelete = [];
    $skipped = 0;
    
    foreach ($sessions as $session) {
        // Decrypt user token to get Webling user ID
        $weblingUserId = $uidEncryptor->decrypt($session['user_token']);
        
        if ($weblingUserId === null) {
            // Skip sessions with invalid/undecryptable tokens
            $skipped++;
            echo "[WARNING] Skipping session {$session['session_id']} - failed to decrypt user token\n";
            continue;
        }
        
        // Create unique key: Webling User ID + API Key
        $key = $weblingUserId . '|' . $session['api_key'];
        
        if (isset($seen[$key])) {
            // Duplicate found - mark for deletion (older session, since we ORDER BY created_at DESC)
            $toDelete[] = $session['session_id'];
            echo "[INFO] Duplicate found: Session {$session['session_id']} (User ID: {$weblingUserId}, API Key: {$session['api_key']})\n";
        } else {
            // First occurrence - keep it
            $seen[$key] = $session['session_id'];
        }
    }
    
    // Delete duplicate sessions
    $deletedCount = 0;
    if (!empty($toDelete)) {
        echo "[INFO] Deleting " . count($toDelete) . " duplicate sessions...\n";
        
        foreach ($toDelete as $sessionId) {
            echo "\tDeleting session {$sessionId}...\n";
            if ($db->deleteSession($sessionId)) {
                $deletedCount++;
            } else {
                echo "\t[ERROR] Failed to delete session {$sessionId}, maybe already deleted?\n";
            }
        }
    }
    
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] Cleanup completed:\n";
    echo "  - Total sessions scanned: " . count($sessions) . "\n";
    echo "  - Unique user/API-key combinations: " . count($seen) . "\n";
    echo "  - Duplicate sessions deleted: {$deletedCount}\n";
    echo "  - Sessions with invalid tokens (skipped): {$skipped}\n";
    
} catch (\Exception $e) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

/**
 * Get PDO connection to access database directly
 * 
 * IMPORTANT: Must use same path as Database::fromEnv()
 * 
 * @return PDO
 */
function getPdoConnection(): PDO {
    $databasePath = getenv('DATABASE_PATH');
    
    if (!$databasePath) {
        // Use same default as Database::fromEnv() in Storage.php
        $databasePath = defined('DATA_FILE') ? DATA_FILE : __DIR__ . '/../storage/data.db';
    }
    
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    return $pdo;
}
