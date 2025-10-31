<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Auth\ApiKeyManager;

require_once __DIR__ . '/bootstrap.php';

class ApiKeyManagerTest extends TestCase {
    
    private array $testApiKeys;
    private ApiKeyManager $manager;
    
    protected function setUp(): void {
        $this->testApiKeys = [
            'full-access-key' => [
                'name' => 'Full Access App',
                'permissions' => ['user_info', 'user_token']
            ],
            'info-only-key' => [
                'name' => 'Info Only App',
                'permissions' => ['user_info']
            ],
            'token-only-key' => [
                'name' => 'Token Only App',
                'permissions' => ['user_token']
            ],
            'minimal-key' => [
                'name' => 'Minimal App',
                'permissions' => []
            ],
        ];
        
        $this->manager = new ApiKeyManager($this->testApiKeys);
    }
    
    public function testIsValidApiKeyWithValidKey(): void {
        $this->assertTrue($this->manager->isValidApiKey('full-access-key'));
        $this->assertTrue($this->manager->isValidApiKey('info-only-key'));
        $this->assertTrue($this->manager->isValidApiKey('minimal-key'));
    }
    
    public function testIsValidApiKeyWithInvalidKey(): void {
        $this->assertFalse($this->manager->isValidApiKey('invalid-key'));
        $this->assertFalse($this->manager->isValidApiKey(''));
        $this->assertFalse($this->manager->isValidApiKey('non-existent'));
    }
    
    public function testGetApiKeyConfig(): void {
        $config = $this->manager->getApiKeyConfig('full-access-key');
        
        $this->assertNotNull($config);
        $this->assertArrayHasKey('name', $config);
        $this->assertArrayHasKey('permissions', $config);
        $this->assertSame('Full Access App', $config['name']);
        $this->assertSame(['user_info', 'user_token'], $config['permissions']);
    }
    
    public function testGetApiKeyConfigWithInvalidKey(): void {
        $config = $this->manager->getApiKeyConfig('invalid-key');
        $this->assertNull($config);
    }
    
    public function testHasPermissionWithFullAccess(): void {
        $this->assertTrue($this->manager->hasPermission('full-access-key', 'user_info'));
        $this->assertTrue($this->manager->hasPermission('full-access-key', 'user_token'));
    }
    
    public function testHasPermissionWithPartialAccess(): void {
        $this->assertTrue($this->manager->hasPermission('info-only-key', 'user_info'));
        $this->assertFalse($this->manager->hasPermission('info-only-key', 'user_token'));
        
        $this->assertFalse($this->manager->hasPermission('token-only-key', 'user_info'));
        $this->assertTrue($this->manager->hasPermission('token-only-key', 'user_token'));
    }
    
    public function testHasPermissionWithNoPermissions(): void {
        $this->assertFalse($this->manager->hasPermission('minimal-key', 'user_info'));
        $this->assertFalse($this->manager->hasPermission('minimal-key', 'user_token'));
    }
    
    public function testHasPermissionWithInvalidKey(): void {
        $this->assertFalse($this->manager->hasPermission('invalid-key', 'user_info'));
    }
    
    public function testCanAccessDefaultRoutes(): void {
        // All valid API keys should access default routes
        $defaultRoutes = [
            '/login',
            '/validate/abc123',
            '/session/check/xyz789',
            '/session/touch/def456',
            '/session/logout/ghi012',
        ];
        
        foreach ($defaultRoutes as $route) {
            $this->assertTrue($this->manager->canAccessRoute('full-access-key', $route));
            $this->assertTrue($this->manager->canAccessRoute('info-only-key', $route));
            $this->assertTrue($this->manager->canAccessRoute('minimal-key', $route));
        }
    }
    
    public function testCanAccessUserInfoRoute(): void {
        $route = '/user/abc123/info';
        
        // Keys with user_info permission can access
        $this->assertTrue($this->manager->canAccessRoute('full-access-key', $route));
        $this->assertTrue($this->manager->canAccessRoute('info-only-key', $route));
        
        // Keys without user_info permission cannot access
        $this->assertFalse($this->manager->canAccessRoute('token-only-key', $route));
        $this->assertFalse($this->manager->canAccessRoute('minimal-key', $route));
    }
    
    public function testCanAccessUserTokenRoute(): void {
        $route = '/user/xyz789/token';
        
        // Keys with user_token permission can access
        $this->assertTrue($this->manager->canAccessRoute('full-access-key', $route));
        $this->assertTrue($this->manager->canAccessRoute('token-only-key', $route));
        
        // Keys without user_token permission cannot access
        $this->assertFalse($this->manager->canAccessRoute('info-only-key', $route));
        $this->assertFalse($this->manager->canAccessRoute('minimal-key', $route));
    }
    
    public function testCanAccessRouteWithInvalidKey(): void {
        $this->assertFalse($this->manager->canAccessRoute('invalid-key', '/login'));
        $this->assertFalse($this->manager->canAccessRoute('invalid-key', '/user/abc123/info'));
    }
    
    public function testCanAccessUnknownRoute(): void {
        $route = '/unknown/route';
        
        // Unknown routes should not be accessible
        $this->assertFalse($this->manager->canAccessRoute('full-access-key', $route));
    }
    
    public function testGetApiKeyName(): void {
        $this->assertSame('Full Access App', $this->manager->getApiKeyName('full-access-key'));
        $this->assertSame('Info Only App', $this->manager->getApiKeyName('info-only-key'));
        $this->assertSame('Minimal App', $this->manager->getApiKeyName('minimal-key'));
    }
    
    public function testGetApiKeyNameWithInvalidKey(): void {
        $this->assertNull($this->manager->getApiKeyName('invalid-key'));
    }
    
    public function testExtractApiKeyFromXApiKeyHeader(): void {
        // Simulate X-API-Key header
        $_SERVER['HTTP_X_API_KEY'] = 'test-api-key-123';
        
        $apiKey = ApiKeyManager::extractApiKeyFromRequest();
        
        $this->assertSame('test-api-key-123', $apiKey);
        
        // Cleanup
        unset($_SERVER['HTTP_X_API_KEY']);
    }
    
    public function testExtractApiKeyFromAuthorizationHeader(): void {
        // Simulate Authorization: ApiKey header
        $_SERVER['HTTP_AUTHORIZATION'] = 'ApiKey test-api-key-456';
        
        $apiKey = ApiKeyManager::extractApiKeyFromRequest();
        
        $this->assertSame('test-api-key-456', $apiKey);
        
        // Cleanup
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    
    public function testExtractApiKeyFromAuthorizationHeaderCaseInsensitive(): void {
        // Test case-insensitive matching
        $_SERVER['HTTP_AUTHORIZATION'] = 'apikey test-api-key-789';
        
        $apiKey = ApiKeyManager::extractApiKeyFromRequest();
        
        $this->assertSame('test-api-key-789', $apiKey);
        
        // Cleanup
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    
    public function testExtractApiKeyPrefersXApiKeyHeader(): void {
        // Set both headers
        $_SERVER['HTTP_X_API_KEY'] = 'x-api-key-value';
        $_SERVER['HTTP_AUTHORIZATION'] = 'ApiKey auth-key-value';
        
        $apiKey = ApiKeyManager::extractApiKeyFromRequest();
        
        // Should prefer X-API-Key
        $this->assertSame('x-api-key-value', $apiKey);
        
        // Cleanup
        unset($_SERVER['HTTP_X_API_KEY']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    
    public function testExtractApiKeyReturnsNullWhenNotPresent(): void {
        // Ensure no headers are set
        unset($_SERVER['HTTP_X_API_KEY']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        
        $apiKey = ApiKeyManager::extractApiKeyFromRequest();
        
        $this->assertNull($apiKey);
    }
    
    public function testExtractApiKeyIgnoresInvalidAuthorizationFormat(): void {
        // Invalid format (not "ApiKey ...")
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-token';
        
        $apiKey = ApiKeyManager::extractApiKeyFromRequest();
        
        $this->assertNull($apiKey);
        
        // Cleanup
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    
    public function testExtractApiKeyTrimsWhitespace(): void {
        $_SERVER['HTTP_AUTHORIZATION'] = 'ApiKey   test-key-with-spaces   ';
        
        $apiKey = ApiKeyManager::extractApiKeyFromRequest();
        
        $this->assertSame('test-key-with-spaces', $apiKey);
        
        // Cleanup
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
    
    public function testRoutePatternMatchingWithVariousSessionIds(): void {
        $sessionIds = [
            'abc123def456',
            'xyz789ghi012',
            'a1b2c3d4e5f6',
            'zyxwvutsrqpo',
        ];
        
        foreach ($sessionIds as $sessionId) {
            $this->assertTrue(
                $this->manager->canAccessRoute('minimal-key', "/validate/{$sessionId}"),
                "Should match /validate/{$sessionId}"
            );
            $this->assertTrue(
                $this->manager->canAccessRoute('minimal-key', "/session/check/{$sessionId}"),
                "Should match /session/check/{$sessionId}"
            );
            $this->assertTrue(
                $this->manager->canAccessRoute('full-access-key', "/user/{$sessionId}/info"),
                "Should match /user/{$sessionId}/info"
            );
        }
    }
}
