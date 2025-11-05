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
     * GET /user/{session_id}/info
     * 
     * Get user information from Webling.
     * Session is extended (children preserved if parent session).
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body
     * @return array Response with user data and new session_id
     * @throws InvalidSessionException if session not found, access denied, or not validated
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
        
        // Check if session has user token
        if ($user['token'] === null) {
            throw new InvalidSessionException('NO_USER_TOKEN', 'Session has no user token');
        }

        // Get weblingId (decrypt token)
        $weblingId = $this->uidEncryptor->decrypt($user['token']);

        // Fetch user data from Webling
        $weblingUser = $this->weblingClient->getUserDataById((int)$weblingId);

        if (!$weblingUser) {
            throw new StorageException('WEBLING_ERROR', 'Failed to fetch user from Webling');
        }

        // All operations successful - now rotate session
        // Create new session, replacing old one (children preserved if parent)
        $newSession = $this->db->createSession(
            $this->apiKey,
            $session['session_duration'],
            300, // code validity
            $sessionId // Replace old session
        );
        
        // Copy user token from old session to new session
        if ($session['user_token'] !== null) {
            $this->db->setUserToken($newSession['session_id'], $session['user_token']);
            $newSession['user_token'] = $session['user_token'];
        }
        
        // Mark new session as validated
        $this->db->validateSession($newSession['session_id']);

        return $this->success([
            'session_id' => $newSession['session_id'],
            'user' => $weblingUser,
            'session_expires_at' => $newSession['expires_at']
        ]);
    }
    
    /**
     * GET /user/{session_id}/token
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
        
        // Check if session has user token
        if ($user['token'] === null) {
            throw new InvalidSessionException('NO_USER_TOKEN', 'Session has no user token');
        }

        // All operations successful - now rotate session
        // Create new session, replacing old one (children preserved if parent)
        $newSession = $this->db->createSession(
            $this->apiKey,
            $session['session_duration'],
            300, // code validity
            $sessionId // Replace old session
        );
        
        // Copy user token from old session to new session
        if ($session['user_token'] !== null) {
            $this->db->setUserToken($newSession['session_id'], $session['user_token']);
            $newSession['user_token'] = $session['user_token'];
        }
        
        // Mark new session as validated
        $this->db->validateSession($newSession['session_id']);

        return $this->success([
            'session_id' => $newSession['session_id'],
            'token' => $user['token'],
            'session_expires_at' => $newSession['expires_at']
        ]);
    }
    
    /**
     * GET /user/{session_id}/id
     * 
     * Get user Webling ID from session with session rotation.
     * Requires 'user_id' permission.
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body
     * @return array Response with user_id and new session_id
     * @throws InvalidSessionException if session not found, not validated, or access denied
     * @throws UserNotFoundException if user not found
     * @throws StorageException if session refresh fails
     */
    public function getId(array $pathVars, array $body): array {
        $sessionId = $pathVars['session_id'];
        
        // Get session with access check
        $session = $this->getSessionWithAccess($sessionId);
        
        // Require user_id permission
        $this->requirePermission('user_id');
        
        // Check if session is validated
        if (!$session['validated']) {
            throw new InvalidSessionException();
        }

        // Get user
        $user = $this->db->getUserBySessionId($sessionId);
        
        if (!$user) {
            throw new UserNotFoundException();
        }
        
        // Check if session has user token
        if ($user['token'] === null) {
            throw new InvalidSessionException('NO_USER_TOKEN', 'Session has no user token');
        }

        // Get weblingId (decrypt token)
        $weblingId = $this->uidEncryptor->decrypt($user['token']);

        // All operations successful - now rotate session
        // Create new session, replacing old one (children preserved if parent)
        $newSession = $this->db->createSession(
            $this->apiKey,
            $session['session_duration'],
            300, // code validity
            $sessionId // Replace old session
        );
        
        // Copy user token from old session to new session
        if ($session['user_token'] !== null) {
            $this->db->setUserToken($newSession['session_id'], $session['user_token']);
            $newSession['user_token'] = $session['user_token'];
        }
        
        // Mark new session as validated
        $this->db->validateSession($newSession['session_id']);

        return $this->success([
            'session_id' => $newSession['session_id'],
            'user_id' => $weblingId,
            'session_expires_at' => $newSession['expires_at']
        ]);
    }
}