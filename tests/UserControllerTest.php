<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Http\Controllers\{UserController, SessionController};
use IwhebAPI\UserAuth\Database\{Database, UidEncryptor};
use IwhebAPI\UserAuth\Auth\{Authorizer, ApiKeyManager};
use IwhebAPI\UserAuth\Http\{Response, WeblingClient};
use IwhebAPI\UserAuth\Exception\{InvalidSessionException, UserNotFoundException};
use IwhebAPI\UserAuth\Exception\Http\InvalidInputException;

require_once __DIR__ . '/bootstrap.php';

class UserControllerTest extends TestCase {
    private Database $db;
    private string $dbFile;
    private UserController $userController;
    private UidEncryptor $encryptor;
    private array $config;
    private string $apiKey;
    
    protected function setUp(): void {
        $this->dbFile = sys_get_temp_dir() . '/user_controller_test_' . bin2hex(random_bytes(6)) . '.db';
        $this->db = new Database($this->dbFile);
        
        $this->config = [
            'keys' => [
                'test-key' => [
                    'name' => 'Test Key',
                    'permissions' => ['user_info', 'user_token', 'user_id']
                ],
                'info-only-key' => [
                    'name' => 'Info Only',
                    'permissions' => ['user_info']
                ]
            ],
            'rate_limit' => [
                'default' => ['window_seconds' => 60, 'max_requests' => 100]
            ]
        ];
        
        $this->apiKey = 'test-key';
        $this->encryptor = new UidEncryptor(UidEncryptor::generateKey());
        
        $response = new Response();
        $authorizer = new Authorizer($this->config);
        $apiKeyManager = new ApiKeyManager($this->config['keys']);
        $weblingClient = new MockWeblingClientForUser('demo', 'key');
        
        $this->userController = new UserController(
            $this->db, $response, $authorizer, $apiKeyManager,
            $this->config, $this->apiKey, $weblingClient, $this->encryptor
        );
    }
    
    protected function tearDown(): void {
        if (file_exists($this->dbFile)) @unlink($this->dbFile);
        $walFile = $this->dbFile . '-wal';
        $shmFile = $this->dbFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }
    
    public function testGetInfoThrowsWhenSessionNotValidated(): void {
        // User creation removed - using token directly: 'token123'
        $session = createSessionWithToken($this->db, 'token123', $this->apiKey);
        // Session is not validated yet
        
        $this->expectException(InvalidSessionException::class);
        
        $this->userController->getInfo(['session_id' => $session['session_id']], []);
    }
    
    public function testGetInfoReturnsUserDataWhenValid(): void {
        // User creation removed - using token directly: 'token456'
        $session = createSessionWithToken($this->db, 'token456', $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $result = $this->userController->getInfo(['session_id' => $session['session_id']], []);
        
        $this->assertArrayHasKey('data', $result);
                $this->assertArrayHasKey('user', $result['data']);
        $this->assertArrayHasKey('session_expires_at', $result['data']);
    }
    
    public function testGetIdReturnsWeblingIdWithNewSession(): void {
        // Create user with known Webling ID
        $weblingId = '12345';
        $userToken = $this->encryptor->encrypt($weblingId);
        
        // Create and validate session - User table removed, using token directly
        $session = createSessionWithToken($this->db, $userToken, $this->apiKey);
        $this->db->validateSession($session['session_id']);
        $originalSessionId = $session['session_id'];
        
        // Call getId
        $result = $this->userController->getId(['session_id' => $originalSessionId], []);
        
        // Verify response structure
        $this->assertArrayHasKey('data', $result);
        $data = $result['data'];
        
        $this->assertArrayHasKey('session_id', $data);
        $this->assertArrayHasKey('session_expires_at', $data);
        $this->assertArrayHasKey('user_id', $data);
        
        // Verify new session was created
        $this->assertNotSame($originalSessionId, $data['session_id']);
        
        // Verify Webling ID was correctly decrypted
        $this->assertSame($weblingId, $data['user_id']);
    }
    
    public function testGetIdThrowsForInvalidSession(): void {
        $this->expectException(InvalidSessionException::class);
        $this->userController->getId(['session_id' => 'invalid'], []);
    }
    
    public function testGetIdThrowsForUnvalidatedSession(): void {
        $userToken = $this->encryptor->encrypt('12345');
        // User table removed - creating session directly with encrypted token
        $session = createSessionWithToken($this->db, $userToken, $this->apiKey);
        
        $this->expectException(InvalidSessionException::class);
        $this->userController->getId(['session_id' => $session['session_id']], []);
    }
    
    public function testGetInfoContainsUserData(): void {
        // User creation removed - using token directly: 'token456'
        $session = createSessionWithToken($this->db, 'token456', $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $result = $this->userController->getInfo(['session_id' => $session['session_id']], []);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('session_id', $result['data']);
        $this->assertArrayHasKey('user', $result['data']);
        $this->assertArrayHasKey('session_expires_at', $result['data']);
        $this->assertSame('Test', $result['data']['user']['firstName']);
    }
    
    public function testGetInfoCreatesNewSession(): void {
        // User creation removed - using token directly: 'token789'
        $session = createSessionWithToken($this->db, 'token789', $this->apiKey);
        $this->db->validateSession($session['session_id']);
        $oldSessionId = $session['session_id'];
        
        $result = $this->userController->getInfo(['session_id' => $oldSessionId], []);
        
        $newSessionId = $result['data']['session_id'];
        $this->assertNotSame($oldSessionId, $newSessionId);
        
        // Old session should be gone
        $this->assertNull($this->db->getSessionBySessionId($oldSessionId));
    }
    
    public function testGetTokenReturnsEncryptedToken(): void {
        // User creation removed - using token directly: 'token-get'
        $session = createSessionWithToken($this->db, 'token-get', $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $result = $this->userController->getToken(['session_id' => $session['session_id']], []);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('token', $result['data']);
        $this->assertSame('token-get', $result['data']['token']);
    }
    
    public function testGetTokenThrowsWhenSessionNotValidated(): void {
        // User creation removed - using token directly: 'token-unvalidated'
        $session = createSessionWithToken($this->db, 'token-unvalidated', $this->apiKey);
        
        $this->expectException(InvalidSessionException::class);
        
        $this->userController->getToken(['session_id' => $session['session_id']], []);
    }
}

class MockWeblingClientForUser extends \IwhebAPI\UserAuth\Http\WeblingClient {
    public function getUserDataById(int $userId): ?array {
        return [
            'id' => $userId,
            'firstName' => 'Test',
            'lastName' => 'User',
            'email' => 'test@example.com'
        ];
    }
}
