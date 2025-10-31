<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Http\Controllers;

use IwhebAPI\UserAuth\Exception\InvalidSessionException;
use IwhebAPI\UserAuth\Exception\Database\StorageException;

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
     * Refresh session by updating last activity and generating new session ID.
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body
     * @return array Response with new session_id and expiration
     * @throws InvalidSessionException if session not found or access denied
     * @throws StorageException if session refresh fails
     */
    public function touch(array $pathVars, array $body): array {
        $sessionId = $pathVars['session_id'];
        
        // Get session with access check
        $this->getSessionWithAccess($sessionId);
        
        // Check if session is active (not expired)
        if (!$this->db->isSessionActive($sessionId)) {
            throw new InvalidSessionException();
        }

        // Touch user to refresh session and update last activity
        $newSessionId = $this->db->touchUser($sessionId);

        if (!$newSessionId) {
            throw new StorageException('STORAGE_ERROR', 'Failed to refresh session');
        }

        // Get the new session to retrieve expires_at
        $newSession = $this->db->getSessionBySessionId($newSessionId);
        
        if (!$newSession) {
            throw new StorageException('STORAGE_ERROR', 'Failed to retrieve new session');
        }

        return $this->success([
            'session_id' => $newSessionId,
            'expires_at' => $newSession['expires_at']
        ]);
    }
}
