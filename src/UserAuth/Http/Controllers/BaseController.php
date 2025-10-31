<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Http\Controllers;

use IwhebAPI\UserAuth\Database\Database;
use IwhebAPI\UserAuth\Auth\{Authorizer, ApiKeyManager};
use IwhebAPI\UserAuth\Http\Response;
use IwhebAPI\UserAuth\Exception\InvalidSessionException;

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
            $this->response->notFound($this->apiKey, 'FORBIDDEN');
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
