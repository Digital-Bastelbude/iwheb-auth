<?php
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Validation;

use iwhebAPI\UserAuth\Http\{SmtpMailer, WeblingClient};

/**
 * EmailValidationProvider
 * 
 * Sends authentication codes via email using SMTP.
 */
class EmailValidationProvider implements ValidationProviderInterface {
    private WeblingClient $weblingClient;
    
    /**
     * Constructor
     * 
     * @param WeblingClient $weblingClient Webling client for user lookup
     */
    public function __construct(WeblingClient $weblingClient) {
        $this->weblingClient = $weblingClient;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'email';
    }
    
    /**
     * {@inheritdoc}
     */
    public function sendCode(string $recipient, string $code, string $sessionId, array $config): bool {
        $mailer = SmtpMailer::fromEnv();
        
        // Get email configuration from config
        $emailConfig = $config['email']['login_code'] ?? [];
        $subject = $emailConfig['subject'] ?? 'Your Authentication Code';
        $message = $emailConfig['message'] ?? 'Your authentication code is: ###CODE###';
        $linkBlock = $emailConfig['link_block'] ?? null;
        
        // Only send link block if it's configured and not empty
        if ($linkBlock && strlen(trim($linkBlock)) === 0) {
            $linkBlock = null;
        }
        
        $mailer->sendAuthCode(
            $recipient,
            $subject,
            $message,
            $code,
            $sessionId,
            $linkBlock
        );
        
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function getUserId(string $recipient): ?int {
        return $this->weblingClient->getUserIdByEmail($recipient);
    }
}
