<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Http\Controllers;

use IwhebAPI\UserAuth\Database\{Database, UidEncryptor};
use IwhebAPI\UserAuth\Auth\{Authorizer, ApiKeyManager};
use IwhebAPI\UserAuth\Http\{Response, WeblingClient};
use IwhebAPI\UserAuth\Exception\{InvalidSessionException, UserNotFoundException};
use IwhebAPI\UserAuth\Exception\Database\StorageException;

/**
 * UserController
 * 
 * Handles user-related operations: get user info, get user token.
 */
class UserController extends BaseController {
    private WeblingClient $weblingClient;
    private UidEncryptor $uidEncryptor;
    
    public function __construct(
        Database $db,
        Response $response,
        Authorizer $authorizer,
        ApiKeyManager $apiKeyManager,
        array $config,
        string $apiKey,
        WeblingClient $weblingClient,
        UidEncryptor $uidEncryptor
    ) {
        parent::__construct($db, $response, $authorizer, $apiKeyManager, $config, $apiKey);
        $this->weblingClient = $weblingClient;
        $this->uidEncryptor = $uidEncryptor;
    }
    
    /**
     * POST /user/{session_id}/info
     * 
     * Get user information from Webling. Requires 'user_info' permission.
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body
     * @return array Response with user data
     * @throws InvalidSessionException if session not found, not validated, or access denied
     * @throws UserNotFoundException if user not found
     * @throws StorageException if session refresh or Webling fetch fails
     */
    public function getInfo(array $pathVars, array $body): array {
        $sessionId = $pathVars['session_id'];
        
        // Get session with access check
        $session = $this->getSessionWithAccess($sessionId);
        
        // Require user_info permission
        $this->requirePermission('user_info');
        
        // Check if session is validated
        if (!$session['validated']) {
            throw new InvalidSessionException();
        }

        // Get user
        $user = $this->db->getUserBySessionId($sessionId);
        
        if (!$user) {
            throw new UserNotFoundException();
        }

        // Touch session to extend expiry
        $newSessionId = $this->db->touchUser($sessionId);
        
        if (!$newSessionId) {
            throw new StorageException('STORAGE_ERROR', 'Failed to refresh session');
        }

        // Get weblingId (decrypt uid)
        $weblingId = $this->uidEncryptor->decrypt($user['uid']);

        // Fetch user data from Webling
        $weblingUser = $this->weblingClient->getUserDataById((int)$weblingId);

        if (!$weblingUser) {
            throw new StorageException('WEBLING_ERROR', 'Failed to fetch user from Webling');
        }

        return $this->success([
            'session_id' => $newSessionId,
            'user' => $weblingUser,
            'session_expires_at' => $session['expires_at']
        ]);
    }
    
    /**
     * POST /user/{session_id}/token
     * 
     * Get encrypted user token. Requires 'user_token' permission.
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body
     * @return array Response with encrypted token
     * @throws InvalidSessionException if session not found, not validated, or access denied
     * @throws UserNotFoundException if user not found
     * @throws StorageException if session refresh fails
     */
    public function getToken(array $pathVars, array $body): array {
        $sessionId = $pathVars['session_id'];
        
        // Get session with access check
        $session = $this->getSessionWithAccess($sessionId);
        
        // Require user_token permission
        $this->requirePermission('user_token');
        
        // Check if session is validated
        if (!$session['validated']) {
            throw new InvalidSessionException();
        }

        // Get user
        $user = $this->db->getUserBySessionId($sessionId);
        
        if (!$user) {
            throw new UserNotFoundException();
        }

        // Touch session to extend expiry
        $newSessionId = $this->db->touchUser($sessionId);
        
        if (!$newSessionId) {
            throw new StorageException('STORAGE_ERROR', 'Failed to refresh session');
        }

        return $this->success([
            'session_id' => $newSessionId,
            'token' => $user['uid'],
            'session_expires_at' => $session['expires_at']
        ]);
    }
}
