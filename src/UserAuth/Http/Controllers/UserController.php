<?php
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Http\Controllers;

use iwhebAPI\SessionManagement\Database\Database;
use iwhebAPI\UserAuth\Database\UidEncryptor;
use iwhebAPI\SessionManagement\Auth\{Authorizer, ApiKeyManager};
use iwhebAPI\UserAuth\Http\WeblingClient;
use iwhebAPI\SessionManagement\Exception\InvalidSessionException;
use iwhebAPI\UserAuth\Exception\UserNotFoundException;
use iwhebAPI\SessionManagement\Exception\Database\StorageException;
use iwhebAPI\SessionManagement\Http\Controllers\BaseController;

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
        Authorizer $authorizer,
        ApiKeyManager $apiKeyManager,
        array $config,
        string $apiKey,
        WeblingClient $weblingClient,
        UidEncryptor $uidEncryptor
    ) {
        parent::__construct($db, $authorizer, $apiKeyManager, $config, $apiKey);
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
     * @param array $body Unused
     * @return array Response with user data and new session_id
     * @throws InvalidSessionException if session not found, access denied, or not validated
     * @throws UserNotFoundException if user not found
     * @throws StorageException if session refresh or Webling fetch fails
     */
    public function getInfo(array $pathVars, array $_body): array {
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
        $newSession = $this->db->rotateSession($sessionId, $this->apiKey);
        
        // Re-encrypt and set user token (new nonce)
        $this->db->setUserToken($newSession['session_id'], $this->uidEncryptor->reEncrypt($user['token']));

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
     * @param array $body Unused
     * @return array Response with encrypted token
     * @throws InvalidSessionException if session not found, not validated, or access denied
     * @throws UserNotFoundException if user not found
     * @throws StorageException if session refresh fails
     */
    public function getToken(array $pathVars, array $_body): array {
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
        $newSession = $this->db->rotateSession($sessionId, $this->apiKey);
        
        // Re-encrypt and set user token (new nonce)
        $this->db->setUserToken($newSession['session_id'], $this->uidEncryptor->reEncrypt($user['token']));

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
     * @param array $body Unused
     * @return array Response with user_id and new session_id
     * @throws InvalidSessionException if session not found, not validated, or access denied
     * @throws UserNotFoundException if user not found
     * @throws StorageException if session refresh fails
     */
    public function getId(array $pathVars, array $_body): array {
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

        $uniqueToken = $this->uidEncryptor->encrypt($weblingId, true);

        // All operations successful - now rotate session
        $newSession = $this->db->rotateSession($sessionId, $this->apiKey);
        
        // Re-encrypt and set user token (new nonce)
        $this->db->setUserToken($newSession['session_id'], $this->uidEncryptor->reEncrypt($user['token']));

        return $this->success([
            'session_id' => $newSession['session_id'],
            'user_id' => $uniqueToken,
            'session_expires_at' => $newSession['expires_at']
        ]);
    }
    
    /**
     * GET /user/{session_id}/properties
     * 
     * Get specific user properties from Webling.
     * Session is extended (children preserved if parent session).
     * Requires 'user_properties' permission.
     * 
     * Query parameter:
     * ?properties=Vorname,Name,E-Mail,...
     * 
     * Properties are filtered by:
     * 1. Properties requested in query parameter
     * 2. Properties allowed for API key (from allowed_properties config)
     * 3. Properties that exist in the Webling user object
     * 
     * If a requested property is not allowed or doesn't exist, it's silently omitted.
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body Unused
     * @return array Response with filtered user properties and new session_id
     * @throws InvalidSessionException if session not found, access denied, or not validated
     * @throws UserNotFoundException if user not found
     * @throws StorageException if session refresh or Webling fetch fails
     * @throws InvalidInputException if properties parameter is missing or invalid
     */
    public function getProperties(array $pathVars, array $body): array {
        $sessionId = $pathVars['session_id'];
        
        // Get session with access check
        $session = $this->getSessionWithAccess($sessionId);
        
        // Require user_properties permission
        $this->requirePermission('user_properties');
        
        // Validate input - parse from query string
        $propertiesParam = $_GET['properties'] ?? '';
        if (empty($propertiesParam) || !is_string($propertiesParam)) {
            throw new \iwhebAPI\UserAuth\Exception\Http\InvalidInputException('properties query parameter is required');
        }
        
        // Split comma-separated properties
        $requestedProperties = array_map('trim', explode(',', $propertiesParam));
        if (empty($requestedProperties)) {
            throw new \iwhebAPI\UserAuth\Exception\Http\InvalidInputException('properties parameter must contain at least one property');
        }
        
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

        // Fetch full user data from Webling
        $weblingUser = $this->weblingClient->getUserDataById((int)$weblingId);

        if (!$weblingUser) {
            throw new StorageException('WEBLING_ERROR', 'Failed to fetch user from Webling');
        }

        // Get allowed properties from API key config
        $apiKeyConfig = $this->apiKeyManager->getApiKeyConfig($this->apiKey);
        $allowedProperties = $apiKeyConfig['allowed_properties'] ?? null;
        
        // Filter properties
        $filteredProperties = [];
        
        // If allowed_properties is defined in API key config, use it as filter
        // Otherwise, allow all requested properties
        $effectiveAllowedProperties = $allowedProperties ?? $requestedProperties;
        
        foreach ($requestedProperties as $property) {
            // Check if property is allowed and exists in Webling user data
            if (in_array($property, $effectiveAllowedProperties, true) && 
                isset($weblingUser['properties'][$property])) {
                $filteredProperties[$property] = $weblingUser['properties'][$property];
            }
        }

        // All operations successful - now rotate session
        $newSession = $this->db->rotateSession($sessionId, $this->apiKey);
        
        // Re-encrypt and set user token (new nonce)
        $this->db->setUserToken($newSession['session_id'], $this->uidEncryptor->reEncrypt($user['token']));

        return $this->success([
            'session_id' => $newSession['session_id'],
            'properties' => $filteredProperties,
            'session_expires_at' => $newSession['expires_at']
        ]);
    }
}