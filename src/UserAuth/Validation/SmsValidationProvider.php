<?php
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Validation;

use iwhebAPI\UserAuth\Http\{SevenClient, WeblingClient};

/**
 * SmsValidationProvider
 * 
 * Sends authentication codes via SMS using Seven.io.
 */
class SmsValidationProvider implements ValidationProviderInterface {
    private WeblingClient $weblingClient;
    private SevenClient $sevenClient;
    private string $phoneField;
    
    /**
     * Constructor
     * 
     * @param WeblingClient $weblingClient Webling client for user lookup
     * @param SevenClient $sevenClient Seven.io client for SMS sending
     * @param string $phoneField Webling field name for phone numbers (default: 'Telefon 1')
     */
    public function __construct(
        WeblingClient $weblingClient,
        SevenClient $sevenClient,
        string $phoneField = 'Telefon 1'
    ) {
        $this->weblingClient = $weblingClient;
        $this->sevenClient = $sevenClient;
        $this->phoneField = $phoneField;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'sms';
    }
    
    /**
     * {@inheritdoc}
     */
    public function sendCode(string $recipient, string $code, string $sessionId, array $config): bool {
        // Get optional sender name from environment
        $senderName = getenv('SEVEN_SENDER_NAME') ?: null;
        
        $this->sevenClient->sendAuthCode($recipient, $code, $senderName);
        
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getUserId(string $recipient): ?int {
        return $this->weblingClient->getUserIdByPhone($recipient, $this->phoneField);
    }
}
