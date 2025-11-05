<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Http\Controllers\SessionController;
use IwhebAPI\UserAuth\Database\{Database, UidEncryptor};
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
    private UidEncryptor $encryptor;
    
    protected function setUp(): void {
        $this->dbFile = sys_get_temp_dir() . '/session_controller_test_' . bin2hex(random_bytes(6)) . '.db';
        $this->encryptor = UidEncryptor::fromEnv(); // Use environment encryptor
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
        // User creation removed - using token directly: 'token-check'
        $session = createSessionWithToken($this->db, 'token-check', $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $result = $this->sessionController->check(['session_id' => $session['session_id']], []);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertTrue($result['data']['active']);
    }
    
    public function testCheckThrowsWhenSessionNotValidated(): void {
        // User creation removed - using token directly: 'token-not-validated'
        $session = createSessionWithToken($this->db, 'token-not-validated', $this->apiKey);
        
        $this->expectException(InvalidSessionException::class);
        
        $this->sessionController->check(['session_id' => $session['session_id']], []);
    }
    
    public function testTouchExtendsSession(): void {
        // User creation removed - using token directly: 'token-touch'
        $session = createSessionWithToken($this->db, 'token-touch', $this->apiKey);
        $oldExpiry = $session['expires_at'];
        
        sleep(1); // Ensure time difference
        
        $result = $this->sessionController->touch(['session_id' => $session['session_id']], []);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('expires_at', $result['data']);
        $this->assertGreaterThan($oldExpiry, $result['data']['expires_at']);
    }
    
    public function testTouchReturnsSameSessionId(): void {
        // User creation removed - using token directly: 'token-same-id'
        $session = createSessionWithToken($this->db, 'token-same-id', $this->apiKey);
        $originalId = $session['session_id'];
        
        $result = $this->sessionController->touch(['session_id' => $originalId], []);
        
        $this->assertSame($originalId, $result['data']['session_id']);
    }
    
    public function testCreateDelegatedThrowsWhenTargetKeyMissing(): void {
        // Create encrypted user token
        $userToken = $this->encryptor->encrypt('12345');
        $session = createSessionWithToken($this->db, $userToken, $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('target_api_key required');
        
        $this->sessionController->createDelegated(['session_id' => $session['session_id']], []);
    }
    
    public function testCreateDelegatedSuccessWithValidInput(): void {
        // Create encrypted user token
        $userToken = $this->encryptor->encrypt('12345');
        $session = createSessionWithToken($this->db, $userToken, $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $result = $this->sessionController->createDelegated(
            ['session_id' => $session['session_id']], 
            ['target_api_key' => 'target-key']
        );
        
        $this->assertArrayHasKey('data', $result);
        
        // Check new session data (for current API key)
        $this->assertArrayHasKey('session_id', $result['data']);
        $this->assertArrayHasKey('expires_at', $result['data']);
        $this->assertNotSame($session['session_id'], $result['data']['session_id']); // New session created
        
        // Check delegated session data
        $this->assertArrayHasKey('delegated_session', $result['data']);
        $delegatedData = $result['data']['delegated_session'];
        $this->assertArrayHasKey('session_id', $delegatedData);
        $this->assertArrayHasKey('expires_at', $delegatedData);
        $this->assertTrue($delegatedData['validated']);
        $this->assertSame('target-key', $delegatedData['api_key']);
    }
    
    public function testCreateDelegatedThrowsWhenTargetIsSameApiKey(): void {
        // Create encrypted user token
        $userToken = $this->encryptor->encrypt('12345');
        $session = createSessionWithToken($this->db, $userToken, $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Cannot delegate session to the same API key');
        
        // Try to delegate to the same API key that created the session
        $this->sessionController->createDelegated(
            ['session_id' => $session['session_id']], 
            ['target_api_key' => $this->apiKey] // Same API key!
        );
    }
    
    public function testCreateDelegatedThrowsWhenParentIsChild(): void {
        // Create encrypted user token
        $userToken = $this->encryptor->encrypt('12345');
        $parentSession = createSessionWithToken($this->db, $userToken, $this->apiKey);
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
