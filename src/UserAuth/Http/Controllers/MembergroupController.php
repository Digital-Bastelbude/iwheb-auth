<?php
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Http\Controllers;

use iwhebAPI\SessionManagement\Database\Database;
use iwhebAPI\SessionManagement\Auth\{Authorizer, ApiKeyManager};
use iwhebAPI\UserAuth\Http\WeblingClient;
use iwhebAPI\SessionManagement\Exception\InvalidSessionException;
use iwhebAPI\SessionManagement\Exception\Database\StorageException;
use iwhebAPI\UserAuth\Exception\Http\InvalidInputException;
use iwhebAPI\SessionManagement\Http\Controllers\BaseController;

/**
 * MembergroupController
 * 
 * Handles membergroup-related operations: get membergroup data, check membership.
 */
class MembergroupController extends BaseController {
    private WeblingClient $weblingClient;
    
    public function __construct(
        Database $db,
        Authorizer $authorizer,
        ApiKeyManager $apiKeyManager,
        array $config,
        string $apiKey,
        WeblingClient $weblingClient
    ) {
        parent::__construct($db, $authorizer, $apiKeyManager, $config, $apiKey);
        $this->weblingClient = $weblingClient;
    }
    
    /**
     * GET /membergroup/{session_id}/{membergroup_id}
     * 
     * Get membergroup data from Webling including subgroups and member IDs.
     * Session is extended (children preserved if parent session).
     * Requires 'membergroup_info' permission.
     * 
     * @param array $pathVars ['session_id' => string, 'membergroup_id' => string]
     * @param array $body Unused
     * @return array Response with membergroup data and new session_id
     * @throws InvalidSessionException if session not found, access denied, or not validated
     * @throws StorageException if session refresh or Webling fetch fails
     * @throws InvalidInputException if membergroup_id is invalid
     */
    public function getMembergroup(array $pathVars, array $_body): array {
        $sessionId = $pathVars['session_id'];
        $membergroupId = $pathVars['membergroup_id'];
        
        // Validate membergroup_id
        if (!ctype_digit($membergroupId)) {
            throw new InvalidInputException('membergroup_id must be a numeric value');
        }
        
        // Get session with access check
        $session = $this->getSessionWithAccess($sessionId);
        
        // Require membergroup_info permission
        $this->requirePermission('membergroup_info');
        
        // Check if session is validated
        if (!$session['validated']) {
            throw new InvalidSessionException();
        }

        // Fetch membergroup data from Webling
        $membergroup = $this->weblingClient->getMembergroup((int)$membergroupId);

        if (!$membergroup) {
            throw new StorageException('WEBLING_ERROR', 'Failed to fetch membergroup from Webling');
        }

        // Extract relevant data
        $filteredData = [
            'id' => (int)$membergroupId,
            'title' => $membergroup['properties']['title'] ?? null,
            'subgroups' => $membergroup['children']['membergroup'] ?? [],
            'members' => $membergroup['children']['member'] ?? []
        ];

        // All operations successful - now rotate session
        $newSession = $this->db->rotateSession($sessionId, $this->apiKey);

        return $this->success([
            'session_id' => $newSession['session_id'],
            'membergroup' => $filteredData,
            'session_expires_at' => $newSession['expires_at']
        ]);
    }
    
    /**
     * GET /membergroup/{session_id}/{membergroup_name}/member/{user_id}
     * 
     * Check if a user is a member of a membergroup.
     * Session is extended (children preserved if parent session).
     * Requires 'membergroup_check' permission.
     * 
     * Query parameter:
     * - membergroup_name: Name of the membergroup
     * - user_id: Webling user/member ID
     * 
     * @param array $pathVars ['session_id' => string, 'membergroup_name' => string, 'user_id' => string]
     * @param array $body Unused
     * @return array Response with membership check result and new session_id
     * @throws InvalidSessionException if session not found, access denied, or not validated
     * @throws StorageException if session refresh or Webling fetch fails
     * @throws InvalidInputException if parameters are invalid
     */
    public function checkMembership(array $pathVars, array $_body): array {
        $sessionId = $pathVars['session_id'];
        $membergroupName = $pathVars['membergroup_name'];
        $userId = $pathVars['user_id'];
        
        // Validate user_id
        if (!ctype_digit($userId)) {
            throw new InvalidInputException('user_id must be a numeric value');
        }
        
        // Validate membergroup_name
        if (empty($membergroupName) || !is_string($membergroupName)) {
            throw new InvalidInputException('membergroup_name must be a non-empty string');
        }
        
        // Get session with access check
        $session = $this->getSessionWithAccess($sessionId);
        
        // Require membergroup_check permission
        $this->requirePermission('membergroup_check');
        
        // Check if session is validated
        if (!$session['validated']) {
            throw new InvalidSessionException();
        }

        // Check membership via Webling API
        try {
            $isMember = $this->weblingClient->isUserInMembergroup((int)$userId, $membergroupName);
        } catch (\Exception $e) {
            throw new StorageException('WEBLING_ERROR', 'Failed to check membership: ' . $e->getMessage());
        }

        // All operations successful - now rotate session
        $newSession = $this->db->rotateSession($sessionId, $this->apiKey);

        return $this->success([
            'session_id' => $newSession['session_id'],
            'user_id' => (int)$userId,
            'membergroup_name' => $membergroupName,
            'is_member' => $isMember,
            'session_expires_at' => $newSession['expires_at']
        ]);
    }
}
