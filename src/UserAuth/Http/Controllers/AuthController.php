<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Http\Controllers;

use iwhebAPI\SessionManagement\Database\Database;
use iwhebAPI\SessionManagement\Http\Controllers\BaseController;
use iwhebAPI\SessionManagement\Auth\{Authorizer, ApiKeyManager};
use iwhebAPI\SessionManagement\Exception\InvalidSessionException;
use iwhebAPI\SessionManagement\Exception\Database\StorageException;

use iwhebAPI\UserAuth\Database\UidEncryptor;
use iwhebAPI\UserAuth\Http\{SmtpMailer, WeblingClient};
use iwhebAPI\UserAuth\Exception\Http\InvalidInputException;
use iwhebAPI\UserAuth\Exception\{UserNotFoundException, InvalidCodeException};
use iwhebAPI\UserAuth\Validation\ValidationProviderManager;

/**
 * AuthController
 * 
 * Handles authentication-related operations: login, validate, logout.
 */
class AuthController extends BaseController {
    private WeblingClient $weblingClient;
    private UidEncryptor $uidEncryptor;
    private ValidationProviderManager $validationProviderManager;
    
    public function __construct(
        Database $db,
        Authorizer $authorizer,
        ApiKeyManager $apiKeyManager,
        array $config,
        string $apiKey,
        WeblingClient $weblingClient,
        UidEncryptor $uidEncryptor,
        ValidationProviderManager $validationProviderManager
    ) {
        parent::__construct($db, $authorizer, $apiKeyManager, $config, $apiKey);
        $this->weblingClient = $weblingClient;
        $this->uidEncryptor = $uidEncryptor;
        $this->validationProviderManager = $validationProviderManager;
    }
    
    /**
     * POST /login
     * 
     * Initiate login with validation code. Creates session and sends authentication code
     * via the specified validation provider (email or SMS).
     * 
     * @param array $pathVars
     * @param array $body ['email' => base64-encoded email, 'provider' => 'email'|'sms' (optional)]
     * @return array Response with session_id and expiration times
     * @throws InvalidInputException if required fields are missing or invalid
     * @throws UserNotFoundException if user not found in Webling
     * @throws \RuntimeException if sending fails
     */
    public function login(array $pathVars, array $body): array {
        // Validate input - email is required
        if (!isset($body['email'])) {
            throw new InvalidInputException('INVALID_INPUT', 'Email required');
        }

        // Decode base64 URL-safe encoded email
        $encodedEmail = $body['email'];
        $email = base64_decode(strtr($encodedEmail, '-_', '+/'), true);
        
        if ($email === false || empty($email)) {
            throw new InvalidInputException('INVALID_INPUT', 'Invalid email encoding');
        }

        // Find user in Webling by email
        $weblingUserId = $this->weblingClient->getUserIdByEmail($email);
        
        if ($weblingUserId === null) {
            throw new UserNotFoundException();
        }

        // Get user properties from Webling
        $userData = $this->weblingClient->getUserDataById($weblingUserId);
        
        if (!$userData || !isset($userData['properties'])) {
            throw new UserNotFoundException();
        }
        
        $userProperties = $userData['properties'];

        // Get validation provider from body (optional, defaults to email)
        $providerName = $body['provider'] ?? null;
        
        // Get the validation provider (defaults to email if not specified or not found)
        $provider = $this->validationProviderManager->getProvider($providerName);
        
        if ($provider === null) {
            // Fallback to email provider if specified provider not found
            $provider = $this->validationProviderManager->getDefaultProvider();
        }
        
        // Let the provider select the appropriate recipient from user properties
        $recipient = $provider->selectRecipient($userProperties);
        
        if ($recipient === null || empty($recipient)) {
            // If provider can't find recipient, fall back to email provider
            if ($provider->getName() !== 'email') {
                $provider = $this->validationProviderManager->getDefaultProvider();
                $recipient = $provider->selectRecipient($userProperties);
            }
            
            // If still no recipient, throw error
            if ($recipient === null || empty($recipient)) {
                throw new InvalidInputException('INVALID_INPUT', 'No valid recipient found for user');
            }
        }

        // Create session without user token
        $session = $this->db->createSession($this->apiKey);

        // Encrypt Webling user ID
        $token = $this->uidEncryptor->encrypt((string)$weblingUserId);
        
        // Assign token to session
        $this->db->setUserToken($session['session_id'], $token);
        $session['user_token'] = $token;

        // Send authentication code via the selected provider
        $provider->sendCode($recipient, $session['code'], $session['session_id'], $this->config);

        return $this->success([
            'session_id' => $session['session_id'],
            'code_expires_at' => $session['code_valid_until'],
            'session_expires_at' => $session['expires_at']
        ]);
    }
    
    /**
     * POST /validate/{session_id}
     * 
     * Validate a session with its authentication code.
     * Creates new session and preserves child sessions.
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body ['code' => string]
     * @return array Response with new session_id
     * @throws InvalidSessionException if session not found or access denied
     * @throws InvalidInputException if code is missing
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

        // Create new validated session, replacing old one (children preserved)
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
            'validated' => true,
            'session_expires_at' => $newSession['expires_at']
        ]);
    }    /**
     * POST /session/logout/{session_id}
     * 
     * Logout and delete the specific session (and all its children).
     * Does NOT delete other sessions of the same user!
     * 
     * @param array $pathVars ['session_id' => string]
     * @param array $body
     * @return array Success response
     * @throws InvalidSessionException if session not found or access denied
     */
    public function logout(array $pathVars, array $body): array {
        $sessionId = $pathVars['session_id'];
        
        // Get session with access check
        $session = $this->getSessionWithAccess($sessionId);
        
        // Delete this specific session (cascade deletes children)
        $deleted = $this->db->deleteSession($sessionId);
        
        if (!$deleted) {
            throw new InvalidSessionException();
        }

        return $this->success([
            'message' => 'Logged out successfully'
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
