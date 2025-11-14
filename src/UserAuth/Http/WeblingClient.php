<?php
declare(strict_types=1);

namespace iwhebAPI\UserAuth\Http;

use iwhebAPI\UserAuth\Exception\Http\WeblingException;

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
     * Get user ID by phone number
     * 
     * Searches for a member with the given phone number and returns the member ID.
     * Note: Phone numbers are normalized (digits only) for comparison.
     * 
     * @param string $phoneNumber The phone number to search for
     * @param string $phoneField The Webling field name for phone (default: 'Telefon 1')
     * @return int|null The member ID or null if not found
     * @throws WeblingException
     */
    public function getUserIdByPhone(string $phoneNumber, string $phoneField = 'Telefon 1'): ?int {
        // Extract digits from phone number for search
        $digits = preg_replace('/\D/', '', $phoneNumber);
        
        // Search for phone numbers containing these digits
        $filter = '`' . $phoneField . '` LIKE "%' . $digits . '%"';
        $encodedFilter = urlencode($filter);
        
        $result = $this->request("/member?filter={$encodedFilter}");
        
        if (empty($result['objects'])) {
            return null;
        }
        
        // Normalize the search phone number
        $normalizedSearch = $this->normalizePhoneNumber($phoneNumber);
        
        // Check each result for exact match (after normalization)
        foreach ($result['objects'] as $userId) {
            $userProperties = $this->getUserPropertiesById($userId);
            if ($userProperties && isset($userProperties[$phoneField])) {
                $userPhone = $this->normalizePhoneNumber($userProperties[$phoneField]);
                if ($userPhone === $normalizedSearch) {
                    return $userId;
                }
            }
        }
        
        // If no exact match found, return the first result as fallback
        return $result['objects'][0];
    }
    
    /**
     * Normalize phone number for comparison
     * 
     * @param string $phoneNumber Phone number to normalize
     * @return string Normalized phone number (digits only with + prefix if present)
     */
    private function normalizePhoneNumber(string $phoneNumber): string {
        // Remove all non-digit characters except +
        $normalized = preg_replace('/[^\d+]/', '', $phoneNumber);
        
        // Ensure + is only at the start
        if (strpos($normalized, '+') !== false) {
            $normalized = '+' . str_replace('+', '', $normalized);
        }
        
        return $normalized;
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
     * Get user properties by user ID
     * 
     * Retrieves only the properties array for the given member ID.
     * 
     * @param int $userId The member ID
     * @return array|null Member properties or null if not found
     * @throws WeblingException
     */
    public function getUserPropertiesById(int $userId): ?array {
        $userData = $this->getUserDataById($userId);
        
        if ($userData === null || !isset($userData['properties'])) {
            return null;
        }
        
        return $userData['properties'];
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
    
    /**
     * Get user properties by email address
     * 
     * Convenience method that combines getUserIdByEmail() and getUserPropertiesById().
     * 
     * @param string $email The email address
     * @return array|null Member properties or null if not found
     * @throws WeblingException
     */
    public function getUserPropertiesByEmail(string $email): ?array {
        $userId = $this->getUserIdByEmail($email);
        
        if ($userId === null) {
            return null;
        }
        
        return $this->getUserPropertiesById($userId);
    }
    
    /**
     * Get all membergroups
     * 
     * Retrieves all membergroups from Webling API.
     * 
     * @return array List of membergroup IDs
     * @throws WeblingException
     */
    public function getMembergroups(): array {
        $result = $this->request("/membergroup");
        return $result['objects'] ?? [];
    }
    
    /**
     * Get membergroup data by ID
     * 
     * Retrieves complete membergroup data including members.
     * 
     * @param int $groupId The membergroup ID
     * @return array|null Membergroup data or null if not found
     * @throws WeblingException
     */
    public function getMembergroup(int $groupId): ?array {
        try {
            $result = $this->request("/membergroup/{$groupId}");
            return $result;
        } catch (WeblingException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }
    
    /**
     * Get membergroup by name
     * 
     * Searches for a membergroup with the given name.
     * 
     * @param string $groupName The membergroup name to search for
     * @return array|null Membergroup data with ID or null if not found
     * @throws WeblingException
     */
    public function getMemberGroupByName(string $groupName): ?array {
        // Get all membergroups
        $groupIds = $this->getMembergroups();
        
        // Search through each group to find matching name
        foreach ($groupIds as $groupId) {
            $group = $this->getMembergroup($groupId);
            if ($group && isset($group['properties']['title']) && $group['properties']['title'] === $groupName) {
                return [
                    'id' => $groupId,
                    'data' => $group
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Check if user is member of a membergroup
     * 
     * Checks if the given user ID is in the members list of the specified membergroup.
     * 
     * @param int $userId The member/user ID
     * @param string $groupName The membergroup name
     * @return bool True if user is member, false otherwise
     * @throws WeblingException
     */
    public function isUserInMembergroup(int $userId, string $groupName): bool {
        $group = $this->getMemberGroupByName($groupName);
        
        if ($group === null) {
            return false;
        }
        
        $members = $group['data']['links']['member'] ?? [];
        return in_array($userId, $members, true);
    }
}