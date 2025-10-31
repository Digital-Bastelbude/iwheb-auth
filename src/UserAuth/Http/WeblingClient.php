<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Http;

use IwhebAPI\UserAuth\Exception\Http\WeblingException;

/**
 * Webling API Client
 * 
 * Provides methods to interact with the Webling API for user authentication.
 * Documentation: https://vrdeck.webling.ch/api
 */
class WeblingClient {
    private string $apiUrl;
    private string $apiKey;
    
    /**
     * Constructor
     * 
     * @param string $domain The Webling domain (e.g., "demo" for demo.webling.ch)
     * @param string $apiKey The API key for authentication
     */
    public function __construct(string $domain, string $apiKey) {
        $this->apiUrl = "https://{$domain}.webling.ch/api/1";
        $this->apiKey = $apiKey;
    }
    
    /**
     * Make an API request
     * 
     * @param string $endpoint The API endpoint (e.g., "/member")
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param array|null $data Request data for POST/PUT
     * @return array|null Response data or null on failure
     * @throws WeblingException
     */
    private function request(string $endpoint, string $method = 'GET', ?array $data = null): ?array {
        $url = $this->apiUrl . $endpoint;
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'apikey: ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        
        if ($method !== 'GET') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        if ($error) {
            throw new WeblingException("cURL error: {$error}");
        }
        
        // Handle different HTTP status codes
        if ($httpCode === 204) {
            return null; // No content (successful PUT/DELETE)
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMsg = $responseData['error'] ?? "HTTP {$httpCode} error";
            throw new WeblingException($errorMsg, $httpCode);
        }
        
        return $responseData;
    }
    
    /**
     * Get user ID by email address
     * 
     * Searches for a member with the given email address and returns the member ID.
     * 
     * @param string $email The email address to search for
     * @return int|null The member ID or null if not found
     * @throws WeblingException
     */
    public function getUserIdByEmail(string $email): ?int {
        // Use UPPER() function for case-insensitive search
        $filter = 'UPPER(`E-Mail`) = "' . strtoupper($email) . '"';
        $encodedFilter = urlencode($filter);
        
        $result = $this->request("/member?filter={$encodedFilter}");
        
        if (empty($result['objects'])) {
            return null;
        }
        
        // Return first matching member ID
        return $result['objects'][0];
    }
    
    /**
     * Get user data by user ID
     * 
     * Retrieves complete member data for the given member ID.
     * 
     * @param int $userId The member ID
     * @return array|null Member data or null if not found
     * @throws WeblingException
     */
    public function getUserDataById(int $userId): ?array {
        try {
            $result = $this->request("/member/{$userId}");
            return $result;
        } catch (WeblingException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }
    
    /**
     * Get user data by email address
     * 
     * Convenience method that combines getUserIdByEmail() and getUserDataById().
     * 
     * @param string $email The email address
     * @return array|null Member data or null if not found
     * @throws WeblingException
     */
    public function getUserDataByEmail(string $email): ?array {
        $userId = $this->getUserIdByEmail($email);
        
        if ($userId === null) {
            return null;
        }
        
        return $this->getUserDataById($userId);
    }
}