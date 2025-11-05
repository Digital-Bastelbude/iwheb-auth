<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Http\Controllers;

use IwhebAPI\UserAuth\Exception\InvalidSessionException;
use IwhebAPI\UserAuth\Exception\Http\InvalidInputException;
use IwhebAPI\UserAuth\Exception\Database\StorageException;
use IwhebAPI\UserAuth\Database\UidEncryptor;

/**
 * SessionController
 * 
 * Handles session-related operations: check, touch.
 */
class SessionController extends BaseController {
    /**
     * GET /session/check/{session_id}
     * 
     * Check if a session is active and validated.
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body
     * @return array Response with session status
     * @throws InvalidSessionException if session not found, not validated, or access denied
     */
    public function check(array $pathVars, array $body): array {
        $sessionId = $pathVars['session_id'];

        
        // Get session with access check
        $session = $this->getSessionWithAccess($sessionId);
        
        // Check if session is validated and not expired
        if (!$session['validated'] || !$this->db->isSessionActive($sessionId)) {
            throw new InvalidSessionException();
        }

        return $this->success([
            'session_id' => $sessionId,
            'expires_at' => $session['expires_at'],
            'active' => true
        ]);
    }
    
    /**
     * POST /session/touch/{session_id}
     * 
     * Extend session expiry time.
     * Only updates expires_at, does NOT create new session!
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body
     * @return array Response with same session_id and new expiry
     * @throws InvalidSessionException if session not found or access denied
     * @throws StorageException if session refresh fails
     */
    public function touch(array $pathVars, array $body): array {
        $sessionId = $pathVars['session_id'];
        
        // Get session with access check
        $session = $this->getSessionWithAccess($sessionId);
        
        // Check if session is active (not expired)
        if (!$this->db->isSessionActive($sessionId)) {
            throw new InvalidSessionException();
        }

        // Extend session (updates expires_at only)
        $updatedSession = $this->db->touchSession($sessionId, $session['session_duration']);

        if (!$updatedSession) {
            throw new StorageException('STORAGE_ERROR', 'Failed to extend session');
        }

        return $this->success([
            'session_id' => $updatedSession['session_id'], // Same ID!
            'expires_at' => $updatedSession['expires_at']
        ]);
    }    /**
     * POST /session/delegate/{session_id}
     * 
     * Create a delegated session for a different API key.
     * 
     * Rules:
     * - Only one child per parent/API-key combination allowed
     * - Parent session must NOT be a child itself (no nested delegation)
     * - Requires 'delegate_session' permission
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body ['target_api_key' => string]
     * @return array Response with new delegated session
     * @throws InvalidSessionException if session not found, access denied, or is a child
     * @throws InvalidInputException if target_api_key is missing or invalid
     */
    public function createDelegated(array $pathVars, array $body): array {
        $parentSessionId = $pathVars['session_id'];
        
        // Validate input
        if (!isset($body['target_api_key']) || empty($body['target_api_key'])) {
            throw new InvalidInputException('INVALID_INPUT', 'target_api_key required');
        }
        
        $targetApiKey = $body['target_api_key'];
        
        // Get parent session with access check
        $parentSession = $this->getSessionWithAccess($parentSessionId);

        // check if api key wants to delegate to itself (forbidden)
        if($targetApiKey == $parentSession['api_key']) {
            throw new InvalidInputException('INVALID_INPUT', 'Cannot delegate session to the same API key');
        }
        
        // Check if session is a child (no nested delegation!)
        if (isset($parentSession['parent_session_id']) && $parentSession['parent_session_id'] !== null) {
            throw new InvalidSessionException('INVALID_SESSION', 'Cannot delegate from a child session');
        }
        
        // Check if parent session is validated and active
        if (!$parentSession['validated'] || !$this->db->isSessionActive($parentSessionId)) {
            throw new InvalidSessionException();
        }
        
        // Require 'delegate_session' permission
        $this->requirePermission('delegate_session');
        
        // Validate that target_api_key exists
        if (!$this->apiKeyManager->isValidApiKey($targetApiKey)) {
            throw new InvalidInputException('INVALID_API_KEY', 'Target API key does not exist');
        }
        
        // All operations successful - now create delegated session and rotate parent
        // Create delegated session (deletes existing child with same API key)
        $delegatedSession = $this->db->createDelegatedSession($parentSessionId, $targetApiKey);
        
        // Get parent user token to decrypt and re-encrypt for child
        $parentUserToken = $delegatedSession['parent_user_token'];
        
        // Decrypt parent token to get Webling user ID
        $uidEncryptor = UidEncryptor::fromEnv();
        $weblingUserId = $uidEncryptor->decrypt($parentUserToken);
        
        if ($weblingUserId === null) {
            throw new StorageException('INVALID_USER_TOKEN', 'Failed to decrypt parent user token');
        }
        
        // Re-encrypt with new nonce for delegated session
        $newToken = $uidEncryptor->encrypt($weblingUserId);
        $this->db->setUserToken($delegatedSession['session_id'], $newToken);
        $delegatedSession['user_token'] = $newToken;
        
        // Create new session for current API key, replacing old one (children preserved if parent)
        $newSession = $this->db->createSession(
            $this->apiKey,
            $parentSession['session_duration'],
            300, // code validity
            $parentSessionId // Replace old session (reparent delegated session)
        );
        
        // Copy user token from old session to new session
        if ($parentSession['user_token'] !== null) {
            $this->db->setUserToken($newSession['session_id'], $parentSession['user_token']);
            $newSession['user_token'] = $parentSession['user_token'];
        }
        
        // Mark new session as validated
        $this->db->validateSession($newSession['session_id']);

        return $this->success([
            'session_id' => $newSession['session_id'],
            'expires_at' => $newSession['expires_at'],
            'delegated_session' => [
                'session_id' => $delegatedSession['session_id'],
                'expires_at' => $delegatedSession['expires_at'],
                'validated' => true,
                'api_key' => $targetApiKey
            ]
        ]);
    }
}
