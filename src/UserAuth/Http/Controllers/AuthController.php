<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Http\Controllers;

use IwhebAPI\UserAuth\Database\{Database, UidEncryptor};
use IwhebAPI\UserAuth\Auth\{Authorizer, ApiKeyManager};
use IwhebAPI\UserAuth\Http\{Response, SmtpMailer, WeblingClient};
use InvalidInputException;
use UserNotFoundException;
use InvalidSessionException;
use InvalidCodeException;
use StorageException;

/**
 * AuthController
 * 
 * Handles authentication-related operations: login, validate, logout.
 */
class AuthController extends BaseController {
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
     * POST /login
     * 
     * Initiate login by email. Creates session and sends authentication code via email.
     * 
     * @param array $pathVars
     * @param array $body ['email' => base64-encoded email]
     * @return array Response with session_id and expiration times
     * @throws InvalidInputException if email is missing or invalid
     * @throws UserNotFoundException if user not found in Webling
     * @throws \RuntimeException if email sending fails
     */
    public function login(array $pathVars, array $body): array {
        // Validate input
        if (!isset($body['email'])) {
            throw new InvalidInputException('INVALID_INPUT', 'Email required');
        }

        // Decode base64 URL-safe encoded email
        $encodedEmail = $body['email'];
        $email = base64_decode(strtr($encodedEmail, '-_', '+/'), true);
        
        if ($email === false || empty($email)) {
            throw new InvalidInputException('INVALID_INPUT', 'Invalid email encoding');
        }

        // Check if user exists in Webling
        $weblingUserId = $this->weblingClient->getUserIdByEmail($email);
        
        if ($weblingUserId === null) {
            throw new UserNotFoundException();
        }

        // Generate token from Webling user ID
        $token = $this->uidEncryptor->encrypt((string)$weblingUserId);

        // Check if user already exists in database
        $existingUser = $this->db->getUserByToken($token);
        
        if (!$existingUser) {
            // Create new user
            $this->db->createUser($token);
        }

        // Create session for user with API key (generates code automatically)
        $session = $this->db->createSession($token, $this->apiKey);

        // Send authentication code via email
        $this->sendAuthenticationEmail($email, $session);

        return $this->success([
            'session_id' => $session['session_id'],
            'code_expires_at' => $session['code_valid_until'],
            'session_expires_at' => $session['expires_at']
        ]);
    }
    
    /**
     * POST /validate/{session_id}
     * 
     * Validate authentication code for a session.
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body ['code' => string]
     * @return array Response with new session_id and validation status
     * @throws InvalidInputException if code is missing
     * @throws InvalidSessionException if session not found or access denied
     * @throws InvalidCodeException if code is invalid
     * @throws StorageException if session refresh fails
     */
    public function validate(array $pathVars, array $body): array {
        $sessionId = $pathVars['session_id'];
        
        // Get session with access check
        $session = $this->getSessionWithAccess($sessionId);
        
        // Validate input - code must be in body
        if (!isset($body['code'])) {
            throw new InvalidInputException('INVALID_INPUT', 'code required');
        }

        $code = $body['code'];

        // Validate code for the session
        $isValidCode = $this->db->validateCode($sessionId, $code);
        
        if (!$isValidCode) {
            throw new InvalidCodeException();
        }

        // Mark session as validated
        $this->db->validateSession($sessionId);

        // Touch user to generate new session ID
        $newSessionId = $this->db->touchUser($sessionId);

        if (!$newSessionId) {
            throw new StorageException('STORAGE_ERROR', 'Failed to refresh session');
        }

        // Regenerate code for security (so old code can't be reused)
        $this->db->regenerateSessionCode($newSessionId);

        return $this->success([
            'session_id' => $newSessionId,
            'validated' => true,
            'session_expires_at' => $session['expires_at']
        ]);
    }
    
    /**
     * POST /session/logout/{session_id}
     * 
     * Logout and delete a session.
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body
     * @return array Success response
     * @throws InvalidSessionException if session not found or access denied
     */
    public function logout(array $pathVars, array $body): array {
        $sessionId = $pathVars['session_id'];
        
        // Get session with access check
        $this->getSessionWithAccess($sessionId);
        
        // Delete the session
        $deleted = $this->db->deleteSession($sessionId);
        
        if (!$deleted) {
            throw new InvalidSessionException();
        }

        return $this->success([
            'message' => 'Logged out successfully',
            'session_id' => $sessionId
        ]);
    }
    
    /**
     * Send authentication code via email
     * 
     * @param string $email Recipient email address
     * @param array $session Session data with code
     * @throws \RuntimeException if email sending fails
     */
    private function sendAuthenticationEmail(string $email, array $session): void {
        $mailer = SmtpMailer::fromEnv();
        
        // Get email configuration from config
        $emailConfig = $this->config['email']['login_code'] ?? [];
        $subject = $emailConfig['subject'] ?? 'Your Authentication Code';
        $message = $emailConfig['message'] ?? 'Your authentication code is: ###CODE###';
        $linkBlock = $emailConfig['link_block'] ?? null;
        
        // Only send link block if it's configured and not empty
        if ($linkBlock && strlen(trim($linkBlock)) === 0) {
            $linkBlock = null;
        }
        
        $mailer->sendAuthCode(
            $email,
            $subject,
            $message,
            $session['code'],
            $session['session_id'],
            $linkBlock
        );
    }
}
