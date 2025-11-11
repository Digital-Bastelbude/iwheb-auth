<?php
declare(strict_types=1);

namespace iwhebAPI\SessionManagement\Http\Controllers;

use iwhebAPI\SessionManagement\Database\Database;
use iwhebAPI\SessionManagement\Auth\{Authorizer, ApiKeyManager, AuthorizationException};
use iwhebAPI\SessionManagement\Http\Response;
use iwhebAPI\SessionManagement\Exception\InvalidSessionException;

/**
 * BaseController
 * 
 * Base controller providing common dependencies and utilities for all controllers.
 */
abstract class BaseController {
    protected Database $db;
    protected Response $response;
    protected Authorizer $authorizer;
    protected ApiKeyManager $apiKeyManager;
    protected array $config;
    protected string $apiKey;
    
    public function __construct(
        Database $db,
        Response $response,
        Authorizer $authorizer,
        ApiKeyManager $apiKeyManager,
        array $config,
        string $apiKey
    ) {
        $this->db = $db;
        $this->response = $response;
        $this->authorizer = $authorizer;
        $this->apiKeyManager = $apiKeyManager;
        $this->config = $config;
        $this->apiKey = $apiKey;
    }
    
    /**
     * Get a session and verify API key access
     * 
     * @param string $sessionId
     * @return array|null Session data or null
     * @throws InvalidSessionException if session not found or access denied
     */
    protected function getSessionWithAccess(string $sessionId): ?array {
        // Check if API key has access to this session
        if (!$this->db->checkSessionAccess($sessionId, $this->apiKey)) {
            throw new InvalidSessionException();
        }

        // Get session
        $session = $this->db->getSessionBySessionId($sessionId);
        
        if (!$session) {
            throw new InvalidSessionException();
        }
        
        return $session;
    }
    
    /**
     * Verify user has required permission
     * 
     * @param string $permission
     * @throws AuthorizationException if permission denied
     */
    protected function requirePermission(string $permission): void {
        if (!$this->apiKeyManager->hasPermission($this->apiKey, $permission)) {
            throw new AuthorizationException($this->apiKey, 'FORBIDDEN', "Permission '{$permission}' required");
        }
    }
    
    /**
     * Create success response
     * 
     * @param mixed $data
     * @param int $status
     * @return array
     */
    protected function success($data, int $status = 200): array {
        return [
            'data' => $data,
            'status' => $status
        ];
    }
}
