<?php
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Http;

/**
 * Seven.io SMS Client
 * 
 * Provides methods to send SMS messages via Seven.io API.
 * Documentation: https://www.seven.io/de/entwickler/
 */
class SevenClient {
    private string $apiKey;
    private string $apiUrl = 'https://gateway.seven.io/api';
    
    /**
     * Constructor
     * 
     * @param string $apiKey The Seven.io API key
     */
    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }
    
    /**
     * Create client instance from environment variables
     * 
     * Expected env var:
     * - SEVEN_API_KEY
     * 
     * @return self
     * @throws \RuntimeException if SEVEN_API_KEY is not set
     */
    public static function fromEnv(): self {
        $apiKey = $_ENV['SEVEN_API_KEY'] ?? getenv('SEVEN_API_KEY');
        
        if (!$apiKey) {
            throw new \RuntimeException('SEVEN_API_KEY environment variable must be set in config/.secrets.php');
        }
        
        return new self($apiKey);
    }
    
    /**
     * Send SMS message
     * 
     * @param string $to Recipient phone number (international format, e.g., +491234567890)
     * @param string $text Message text (max 1520 characters)
     * @param string|null $from Optional sender name (max 11 characters alphanumeric)
     * @return array Response from Seven.io API
     * @throws \RuntimeException if SMS sending fails
     */
    public function sendSms(string $to, string $text, ?string $from = null): array {
        // Prepare request parameters
        $params = [
            'to' => $to,
            'text' => $text,
            'json' => '1' // Request JSON response
        ];
        
        if ($from !== null) {
            $params['from'] = $from;
        }
        
        // Build URL with query parameters
        $url = $this->apiUrl . '/sms?' . http_build_query($params);
        
        // Initialize cURL
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'X-Api-Key: ' . $this->apiKey,
            'Accept: application/json'
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            throw new \RuntimeException("cURL error: {$error}");
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode >= 400 || !$responseData || (isset($responseData['success']) && !$responseData['success'])) {
            $errorMsg = $responseData['error'] ?? $responseData['message'] ?? "HTTP {$httpCode} error";
            throw new \RuntimeException("Failed to send SMS: {$errorMsg}");
        }
        
        return $responseData;
    }
    
    /**
     * Send authentication code via SMS
     * 
     * Convenience method for sending authentication codes.
     * 
     * @param string $phoneNumber Recipient phone number (international format)
     * @param string $code Authentication code
     * @param string|null $from Optional sender name
     * @return array Response from Seven.io API
     * @throws \RuntimeException if SMS sending fails
     */
    public function sendAuthCode(string $phoneNumber, string $code, ?string $from = null): array {
        $message = "Your authentication code is: {$code}\n\nThis code is valid for 15 minutes.";
        return $this->sendSms($phoneNumber, $message, $from);
    }
}
