<?php
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Validation;

/**
 * ValidationProviderInterface
 * 
 * Interface for validation providers that can send authentication codes
 * via different channels (email, SMS, etc.)
 */
interface ValidationProviderInterface {
    /**
     * Get the provider name
     * 
     * @return string The unique name of this provider (e.g., 'email', 'sms')
     */
    public function getName(): string;
    
    /**
     * Send authentication code to the user
     * 
     * @param string $recipient The recipient identifier (email, phone number, etc.)
     * @param string $code The authentication code
     * @param string $sessionId The session ID
     * @param array $config Additional configuration from config.json
     * @return bool True if code was sent successfully
     * @throws \RuntimeException if sending fails
     */
    public function sendCode(string $recipient, string $code, string $sessionId, array $config): bool;
    
    /**
     * Get the user identifier (Webling user ID) for the recipient
     * 
     * @param string $recipient The recipient identifier (email, phone number, etc.)
     * @return int|null The Webling user ID or null if not found
     * @throws \RuntimeException if lookup fails
     */
    public function getUserId(string $recipient): ?int;
    
    /**
     * Select the appropriate recipient field from user properties
     * 
     * @param array $userProperties User properties from Webling
     * @return string|null The recipient identifier (email, phone number, etc.) or null if not found
     */
    public function selectRecipient(array $userProperties): ?string;
}
