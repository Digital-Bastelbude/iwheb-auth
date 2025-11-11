<?php
/**
 * Test Helper Functions
 * 
 * Common helper functions for tests after Storage.php refactoring.
 */

use iwhebAPI\SessionManagement\Database\Database;

/**
 * Create a session with user token.
 * 
 * This helper wraps the new two-step session creation process:
 * 1. Create session without token
 * 2. Assign encrypted token to session
 * 
 * @param Database $db Database instance
 * @param string $userToken Encrypted user token
 * @param string $apiKey API key
 * @param int $sessionDurationSeconds Session duration in seconds
 * @param int $codeValiditySeconds Code validity in seconds
 * @param string|null $oldSessionId Optional old session ID for reparenting
 * @return array Session data with user_token set
 */
function createSessionWithToken(
    Database $db,
    string $userToken,
    string $apiKey,
    int $sessionDurationSeconds = 1800,
    int $codeValiditySeconds = 300,
    ?string $oldSessionId = null
): array {
    $session = $db->createSession($apiKey, $sessionDurationSeconds, $codeValiditySeconds, $oldSessionId);
    $db->setUserToken($session['session_id'], $userToken);
    $session['user_token'] = $userToken;
    return $session;
}
