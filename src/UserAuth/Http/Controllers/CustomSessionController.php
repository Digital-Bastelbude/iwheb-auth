<?php 
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Http\Controllers;

use iwhebAPI\SessionManagement\Http\Controllers\SessionController;
use iwhebAPI\SessionManagement\Database\Database;
use iwhebAPI\SessionManagement\Auth\{Authorizer, ApiKeyManager};
use iwhebAPI\SessionManagement\Http\Response;
use iwhebAPI\SessionManagement\Exception\InvalidSessionException;
use iwhebAPI\SessionManagement\Exception\Database\StorageException;

use iwhebAPI\UserAuth\Database\Repository\SessionDelegationRepository;
use iwhebAPI\UserAuth\Database\UidEncryptor;
use iwhebAPI\UserAuth\Exception\Http\InvalidInputException;

class CustomSessionController extends SessionController {
    
    private ?SessionDelegationRepository $delegationRepo;
    
    public function __construct(
        Database $db,
        Response $response,
        Authorizer $authorizer,
        ApiKeyManager $apiKeyManager,
        array $config,
        string $apiKey,
        SessionDelegationRepository $delegationRepo
    ) {
        parent::__construct($db, $response, $authorizer, $apiKeyManager, $config, $apiKey);
        $this->delegationRepo = $delegationRepo ?? null;
    }

    /**
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

        // check for delegation repository
        if($this->delegationRepo === null) {
            throw new StorageException('DELEGATION_REPO_MISSING', 'Session delegation repository not initialized');
        }
        
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
        $delegatedSession = $this->delegationRepo->createDelegatedSession($parentSessionId, $targetApiKey);
        
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
        
        // Rotate parent session (creates new session with all data from old)
        $newSession = $this->db->rotateSession($parentSessionId, $this->apiKey);
        
        // Re-encrypt and set user token for new parent session (new nonce)
        $this->db->setUserToken($newSession['session_id'], $uidEncryptor->reEncrypt($parentSession['user_token']));

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