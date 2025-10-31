<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Http;

/**
 * SmtpMailer
 * 
 * Simple SMTP mail sender for sending authentication codes.
 * Supports plain text emails with placeholder replacement.
 */
class SmtpMailer {
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private bool $useTls;
    private $socket = null;
    
    /**
     * @param string $host SMTP server hostname
     * @param int $port SMTP server port (usually 587 for TLS, 465 for SSL, 25 for plain)
     * @param string $username SMTP username
     * @param string $password SMTP password
     * @param string $fromEmail Sender email address
     * @param string $fromName Sender name
     * @param bool $useTls Use TLS encryption (STARTTLS)
     */
    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        string $fromEmail,
        string $fromName = '',
        bool $useTls = true
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName ?: $fromEmail;
        $this->useTls = $useTls;
    }
    
    /**
     * Create mailer instance from environment variables
     * 
     * Expected env vars:
     * - SMTP_HOST
     * - SMTP_PORT
     * - SMTP_USERNAME
     * - SMTP_PASSWORD
     * - SMTP_FROM_EMAIL
     * - SMTP_FROM_NAME (optional)
     * - SMTP_USE_TLS (optional, default true)
     */
    public static function fromEnv(): self {
        $host = $_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST');
        $port = (int)($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?: 587);
        $username = $_ENV['SMTP_USERNAME'] ?? getenv('SMTP_USERNAME');
        $password = $_ENV['SMTP_PASSWORD'] ?? getenv('SMTP_PASSWORD');
        $fromEmail = $_ENV['SMTP_FROM_EMAIL'] ?? getenv('SMTP_FROM_EMAIL');
        $fromName = $_ENV['SMTP_FROM_NAME'] ?? getenv('SMTP_FROM_NAME') ?: '';
        $useTls = filter_var(
            $_ENV['SMTP_USE_TLS'] ?? getenv('SMTP_USE_TLS') ?? 'true',
            FILTER_VALIDATE_BOOLEAN
        );
        
        if (!$host || !$username || !$password || !$fromEmail) {
            throw new \RuntimeException('SMTP configuration incomplete in environment variables');
        }
        
        return new self($host, $port, $username, $password, $fromEmail, $fromName, $useTls);
    }
    
    /**
     * Send an email
     * 
     * @param string $toEmail Recipient email address
     * @param string $subject Email subject
     * @param string $message Email body (plain text)
     * @return bool Success status
     * @throws \RuntimeException on SMTP errors
     */
    public function send(string $toEmail, string $subject, string $message): bool {
        try {
            $this->connect();
            $this->authenticate();
            $this->sendMail($toEmail, $subject, $message);
            $this->disconnect();
            return true;
        } catch (\Exception $e) {
            $this->disconnect();
            throw new \RuntimeException('Failed to send email: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Send authentication code email with placeholder replacement
     * 
     * @param string $toEmail Recipient email address
     * @param string $subject Email subject template (supports ###CODE### placeholder)
     * @param string $messageTemplate Message template with placeholders
     * @param string $code Authentication code
     * @param string $sessionId Session ID
     * @param string|null $linkBlockTemplate Optional link block template
     * @param string $linkBlockPlaceholder Placeholder in message for link block (default: ###LINK_BLOCK###)
     * @return bool Success status
     */
    public function sendAuthCode(
        string $toEmail,
        string $subject,
        string $messageTemplate,
        string $code,
        string $sessionId,
        ?string $linkBlockTemplate = null,
        string $linkBlockPlaceholder = '###LINK_BLOCK###'
    ): bool {
        // Replace placeholders in subject
        $subject = str_replace('###CODE###', $code, $subject);
        
        // Prepare message with code and session ID
        $message = str_replace('###CODE###', $code, $messageTemplate);
        $message = str_replace('###SESSION_ID###', $sessionId, $message);
        
        // Handle optional link block
        if ($linkBlockTemplate && strlen($linkBlockTemplate) > 0) {
            $linkBlock = str_replace('###CODE###', $code, $linkBlockTemplate);
            $linkBlock = str_replace('###SESSION_ID###', $sessionId, $linkBlock);
            $message = str_replace($linkBlockPlaceholder, $linkBlock, $message);
        } else {
            // Remove link block placeholder if no link block provided
            $message = str_replace($linkBlockPlaceholder, '', $message);
        }
        
        return $this->send($toEmail, $subject, $message);
    }
    
    private function connect(): void {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);
        if (!$this->socket) {
            throw new \RuntimeException("Cannot connect to SMTP server: $errstr ($errno)");
        }
        
        stream_set_timeout($this->socket, 10);
        $this->getResponse(); // Read greeting
        
        // Send EHLO
        $this->sendCommand("EHLO {$this->host}");
        
        // Start TLS if enabled
        if ($this->useTls) {
            $this->sendCommand("STARTTLS");
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException("Failed to enable TLS encryption");
            }
            // Send EHLO again after TLS
            $this->sendCommand("EHLO {$this->host}");
        }
    }
    
    private function authenticate(): void {
        $this->sendCommand("AUTH LOGIN");
        $this->sendCommand(base64_encode($this->username));
        $this->sendCommand(base64_encode($this->password));
    }
    
    private function sendMail(string $toEmail, string $subject, string $message): void {
        // MAIL FROM
        $this->sendCommand("MAIL FROM:<{$this->fromEmail}>");
        
        // RCPT TO
        $this->sendCommand("RCPT TO:<{$toEmail}>");
        
        // DATA
        $this->sendCommand("DATA");
        
        // Build email headers and body
        $email = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
        $email .= "To: <{$toEmail}>\r\n";
        $email .= "Subject: {$subject}\r\n";
        $email .= "MIME-Version: 1.0\r\n";
        $email .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $email .= "Content-Transfer-Encoding: 8bit\r\n";
        $email .= "\r\n";
        $email .= $message;
        $email .= "\r\n.\r\n";
        
        fwrite($this->socket, $email);
        $this->getResponse();
    }
    
    private function disconnect(): void {
        if ($this->socket) {
            $this->sendCommand("QUIT", false);
            fclose($this->socket);
            $this->socket = null;
        }
    }
    
    private function sendCommand(string $command, bool $checkResponse = true): void {
        fwrite($this->socket, $command . "\r\n");
        if ($checkResponse) {
            $this->getResponse();
        }
    }
    
    private function getResponse(): string {
        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            // SMTP responses end with a space after the code (e.g., "250 OK")
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        
        // Check for error codes (4xx, 5xx)
        if (preg_match('/^[45]\d{2}/', $response)) {
            throw new \RuntimeException("SMTP Error: $response");
        }
        
        return $response;
    }
}
