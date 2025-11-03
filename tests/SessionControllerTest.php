<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Http\Controllers\SessionController;
use IwhebAPI\UserAuth\Database\Database;
use IwhebAPI\UserAuth\Auth\{Authorizer, ApiKeyManager};
use IwhebAPI\UserAuth\Http\Response;
use IwhebAPI\UserAuth\Exception\InvalidSessionException;
use IwhebAPI\UserAuth\Exception\Http\InvalidInputException;

require_once __DIR__ . '/bootstrap.php';

class SessionControllerTest extends TestCase {
    private Database $db;
    private string $dbFile;
    private SessionController $sessionController;
    private array $config;
    private string $apiKey;
    
    protected function setUp(): void {
        $this->dbFile = sys_get_temp_dir() . '/session_controller_test_' . bin2hex(random_bytes(6)) . '.db';
        $this->db = new Database($this->dbFile);
        
        $this->config = [
            'keys' => [
                'test-key' => [
                    'name' => 'Test App',
                    'permissions' => ['user_info', 'delegate_session']
                ],
                'target-key' => [
                    'name' => 'Target App',
                    'permissions' => ['user_info', 'delegate_session']
                ]
            ],
            'rate_limit' => ['default' => ['window_seconds' => 60, 'max_requests' => 100]]
        ];
        
        $this->apiKey = 'test-key';
        
        $response = new Response();
        $authorizer = new Authorizer($this->config);
        $apiKeyManager = new ApiKeyManager($this->config['keys']);
        
        $this->sessionController = new SessionController(
            $this->db, $response, $authorizer, $apiKeyManager,
            $this->config, $this->apiKey
        );
    }
    
    protected function tearDown(): void {
        if (file_exists($this->dbFile)) @unlink($this->dbFile);
        $walFile = $this->dbFile . '-wal';
        $shmFile = $this->dbFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }
    
    public function testCheckReturnsActiveForValidatedSession(): void {
        $user = $this->db->createUser('token-check');
        $session = $this->db->createSession($user['token'], $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $result = $this->sessionController->check(['session_id' => $session['session_id']], []);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertTrue($result['data']['active']);
    }
    
    public function testCheckThrowsWhenSessionNotValidated(): void {
        $user = $this->db->createUser('token-not-validated');
        $session = $this->db->createSession($user['token'], $this->apiKey);
        
        $this->expectException(InvalidSessionException::class);
        
        $this->sessionController->check(['session_id' => $session['session_id']], []);
    }
    
    public function testTouchExtendsSession(): void {
        $user = $this->db->createUser('token-touch');
        $session = $this->db->createSession($user['token'], $this->apiKey);
        $oldExpiry = $session['expires_at'];
        
        sleep(1); // Ensure time difference
        
        $result = $this->sessionController->touch(['session_id' => $session['session_id']], []);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('expires_at', $result['data']);
        $this->assertGreaterThan($oldExpiry, $result['data']['expires_at']);
    }
    
    public function testTouchReturnsSameSessionId(): void {
        $user = $this->db->createUser('token-same-id');
        $session = $this->db->createSession($user['token'], $this->apiKey);
        $originalId = $session['session_id'];
        
        $result = $this->sessionController->touch(['session_id' => $originalId], []);
        
        $this->assertSame($originalId, $result['data']['session_id']);
    }
    
    public function testCreateDelegatedThrowsWhenTargetKeyMissing(): void {
        $user = $this->db->createUser('token-delegate');
        $session = $this->db->createSession($user['token'], $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('target_api_key required');
        
        $this->sessionController->createDelegated(['session_id' => $session['session_id']], []);
    }
    
    public function testCreateDelegatedSuccessWithValidInput(): void {
        $user = $this->db->createUser('token-delegate-ok');
        $session = $this->db->createSession($user['token'], $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $result = $this->sessionController->createDelegated(
            ['session_id' => $session['session_id']], 
            ['target_api_key' => 'target-key']
        );
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('session_id', $result['data']);
        $this->assertTrue($result['data']['validated']);
        $this->assertSame('target-key', $result['data']['api_key']);
    }
    
    public function testCreateDelegatedThrowsWhenParentIsChild(): void {
        $user = $this->db->createUser('token-nested');
        $parentSession = $this->db->createSession($user['token'], $this->apiKey);
        $this->db->validateSession($parentSession['session_id']);
        
        // Create child session
        $childSession = $this->db->createDelegatedSession($parentSession['session_id'], 'target-key');
        
        // Create a SessionController with target-key to access the child session
        $targetController = new SessionController(
            $this->db,
            new Response(),
            new Authorizer($this->config),
            new ApiKeyManager($this->config['keys']),
            $this->config,
            'target-key' // Use target-key to access child session
        );
        
        $this->expectException(InvalidSessionException::class);
        
        // Try to delegate from child (should fail - nested delegation not allowed)
        $targetController->createDelegated(
            ['session_id' => $childSession['session_id']], 
            ['target_api_key' => 'test-key']
        );
    }
}
