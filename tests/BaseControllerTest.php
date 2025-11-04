<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Http\Controllers\BaseController;
use IwhebAPI\UserAuth\Database\Database;
use IwhebAPI\UserAuth\Auth\{Authorizer, ApiKeyManager};
use IwhebAPI\UserAuth\Http\Response;
use IwhebAPI\UserAuth\Exception\InvalidSessionException;

require_once __DIR__ . '/bootstrap.php';

/**
 * Test BaseController functionality using a concrete test implementation
 */
class BaseControllerTest extends TestCase {
    private Database $db;
    private string $dbFile;
    private TestableBaseController $controller;
    private array $config;
    private string $apiKey;
    
    protected function setUp(): void {
        $this->dbFile = sys_get_temp_dir() . '/base_controller_test_' . bin2hex(random_bytes(6)) . '.db';
        $this->db = new Database($this->dbFile);
        
        $this->config = [
            'keys' => [
                'test-key' => [
                    'name' => 'Test App',
                    'permissions' => ['user_info', 'user_token']
                ],
                'limited-key' => [
                    'name' => 'Limited App',
                    'permissions' => ['user_info']
                ]
            ],
            'rate_limit' => [
                'default' => [
                    'window_seconds' => 60,
                    'max_requests' => 100
                ]
            ]
        ];
        
        $this->apiKey = 'test-key';
        
        $response = new Response();
        $authorizer = new Authorizer($this->config);
        $apiKeyManager = new ApiKeyManager($this->config['keys']);
        
        $this->controller = new TestableBaseController(
            $this->db,
            $response,
            $authorizer,
            $apiKeyManager,
            $this->config,
            $this->apiKey
        );
    }
    
    protected function tearDown(): void {
        if (file_exists($this->dbFile)) {
            @unlink($this->dbFile);
        }
        $walFile = $this->dbFile . '-wal';
        $shmFile = $this->dbFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }
    
    public function testGetSessionWithAccessReturnsSessionWhenValid(): void {
        // Create user and session
        // User creation removed - using token directly: 'user-token-123'
        $session = $this->db->createSession('user-token-123', $this->apiKey);
        
        // Should return session
        $result = $this->controller->publicGetSessionWithAccess($session['session_id']);
        
        $this->assertIsArray($result);
        $this->assertSame($session['session_id'], $result['session_id']);
    }
    
    public function testGetSessionWithAccessThrowsWhenWrongApiKey(): void {
        // Create user and session with different API key
        // User creation removed - using token directly: 'user-token-456'
        $session = $this->db->createSession('user-token-456', 'other-key');
        
        $this->expectException(InvalidSessionException::class);
        
        // Should throw because API key doesn't match
        $this->controller->publicGetSessionWithAccess($session['session_id']);
    }
    
    public function testGetSessionWithAccessThrowsWhenSessionNotFound(): void {
        $this->expectException(InvalidSessionException::class);
        
        $this->controller->publicGetSessionWithAccess('nonexistent-session');
    }
    
    public function testRequirePermissionAllowsWhenPermissionGranted(): void {
        // Should not throw - 'user_info' is in test-key permissions
        $this->controller->publicRequirePermission('user_info');
        
        $this->assertTrue(true); // If we get here, no exception was thrown
    }
    
    public function testSuccessReturnsCorrectStructure(): void {
        $data = ['foo' => 'bar', 'count' => 42];
        
        $result = $this->controller->publicSuccess($data);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertSame($data, $result['data']);
        $this->assertSame(200, $result['status']);
    }
    
    public function testSuccessWithCustomStatus(): void {
        $data = ['message' => 'created'];
        
        $result = $this->controller->publicSuccess($data, 201);
        
        $this->assertSame(201, $result['status']);
        $this->assertSame($data, $result['data']);
    }
}

/**
 * Concrete implementation of BaseController for testing
 * Makes protected methods public for testing
 */
class TestableBaseController extends BaseController {
    public function publicGetSessionWithAccess(string $sessionId): ?array {
        return $this->getSessionWithAccess($sessionId);
    }
    
    public function publicRequirePermission(string $permission): void {
        $this->requirePermission($permission);
    }
    
    public function publicSuccess($data, int $status = 200): array {
        return $this->success($data, $status);
    }
}
