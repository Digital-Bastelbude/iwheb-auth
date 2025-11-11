<?php
declare(strict_types=1);

namespace iwhebAPI\SessionManagement\Http\Controllers;

use iwhebAPI\SessionManagement\Exception\InvalidSessionException;
use iwhebAPI\SessionManagement\Exception\Database\StorageException;

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
    }
}
