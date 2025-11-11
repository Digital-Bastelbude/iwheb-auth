<?php
use PHPUnit\Framework\TestCase;
use iwhebAPI\UserAuth\Http\Controllers\{UserController};
use iwhebAPI\SessionManagement\Database\Database;
use iwhebAPI\UserAuth\Database\UidEncryptor;
use iwhebAPI\SessionManagement\Auth\{Authorizer, ApiKeyManager};
use iwhebAPI\SessionManagement\Http\Response;
use iwhebAPI\SessionManagement\Exception\InvalidSessionException;

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
        
        $authorizer = new Authorizer($this->config);
        $apiKeyManager = new ApiKeyManager($this->config['keys']);
        $weblingClient = new MockWeblingClientForUser('demo', 'key');
        
        $this->userController = new UserController(
            $this->db, $authorizer, $apiKeyManager,
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
        // Create session with encrypted user token
        $session = createSessionWithToken($this->db, $this->encryptor->encrypt('123'), $this->apiKey);
        // Session is not validated yet
        
        $this->expectException(InvalidSessionException::class);
        
        $this->userController->getInfo(['session_id' => $session['session_id']], []);
    }
    
    public function testGetInfoReturnsUserDataWhenValid(): void {
        // Create session with encrypted user token
        $session = createSessionWithToken($this->db, $this->encryptor->encrypt('456'), $this->apiKey);
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
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('session_id', $result['data']);
        $this->assertArrayHasKey('session_expires_at', $result['data']);
        $this->assertArrayHasKey('user_id', $result['data']);

        $newWeblingId = $this->encryptor->decrypt($result['data']['user_id'], true);
        $newSessionId = $result['data']['session_id'];

        // Verify new session was created
        $this->assertNotSame($originalSessionId, $newSessionId);

        // Verify Webling ID was correctly decrypted
        $this->assertSame($weblingId, $newWeblingId);
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
        // Create session with encrypted user token
        $session = createSessionWithToken($this->db, $this->encryptor->encrypt('456'), $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $result = $this->userController->getInfo(['session_id' => $session['session_id']], []);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('session_id', $result['data']);
        $this->assertArrayHasKey('user', $result['data']);
        $this->assertArrayHasKey('session_expires_at', $result['data']);
        $this->assertSame('Test', $result['data']['user']['firstName']);
    }
    
    public function testGetInfoCreatesNewSession(): void {
        // Create session with encrypted user token
        $session = createSessionWithToken($this->db, $this->encryptor->encrypt('789'), $this->apiKey);
        $this->db->validateSession($session['session_id']);
        $oldSessionId = $session['session_id'];
        
        $result = $this->userController->getInfo(['session_id' => $oldSessionId], []);
        
        $newSessionId = $result['data']['session_id'];
        $this->assertNotSame($oldSessionId, $newSessionId);
        
        // Old session should be gone
        $this->assertNull($this->db->getSessionBySessionId($oldSessionId));
    }
    
    public function testGetTokenReturnsEncryptedToken(): void {
        // Create session with encrypted user token
        $encryptedToken = $this->encryptor->encrypt('999');
        $session = createSessionWithToken($this->db, $encryptedToken, $this->apiKey);
        $this->db->validateSession($session['session_id']);
        
        $result = $this->userController->getToken(['session_id' => $session['session_id']], []);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('token', $result['data']);
        $this->assertSame($encryptedToken, $result['data']['token']);
    }
    
    public function testGetTokenThrowsWhenSessionNotValidated(): void {
        // User creation removed - using token directly: 'token-unvalidated'
        $session = createSessionWithToken($this->db, 'token-unvalidated', $this->apiKey);
        
        $this->expectException(InvalidSessionException::class);
        
        $this->userController->getToken(['session_id' => $session['session_id']], []);
    }
    
    public function testGetInfoThrowsWhenMissingUserInfoPermission(): void {
        // Create controller with info-only-key that lacks user_info permission
        $authorizer = new Authorizer($this->config);
        $apiKeyManager = new ApiKeyManager($this->config['keys']);
        $weblingClient = new MockWeblingClientForUser('demo', 'key');
        
        // Create key without user_info permission
        $this->config['keys']['no-info-key'] = [
            'name' => 'No Info Key',
            'permissions' => ['user_token'] // Missing 'user_info'
        ];
        
        $controller = new UserController(
            $this->db, $authorizer, $apiKeyManager,
            $this->config, 'no-info-key', $weblingClient, $this->encryptor
        );
        
        $session = createSessionWithToken($this->db, $this->encryptor->encrypt('123'), 'no-info-key');
        $this->db->validateSession($session['session_id']);
        
        $this->expectException(\iwhebAPI\SessionManagement\Auth\AuthorizationException::class);
        $controller->getInfo(['session_id' => $session['session_id']], []);
    }
    
    public function testGetTokenThrowsWhenMissingUserTokenPermission(): void {
        // Create controller with key that lacks user_token permission
        $authorizer = new Authorizer($this->config);
        $apiKeyManager = new ApiKeyManager($this->config['keys']);
        $weblingClient = new MockWeblingClientForUser('demo', 'key');
        
        // Use info-only-key which has only user_info permission
        $controller = new UserController(
            $this->db, $authorizer, $apiKeyManager,
            $this->config, 'info-only-key', $weblingClient, $this->encryptor
        );
        
        $session = createSessionWithToken($this->db, $this->encryptor->encrypt('456'), 'info-only-key');
        $this->db->validateSession($session['session_id']);
        
        $this->expectException(\iwhebAPI\SessionManagement\Auth\AuthorizationException::class);
        $controller->getToken(['session_id' => $session['session_id']], []);
    }
    
    public function testGetIdThrowsWhenMissingUserIdPermission(): void {
        // Create controller with key that lacks user_id permission
        $authorizer = new Authorizer($this->config);
        $apiKeyManager = new ApiKeyManager($this->config['keys']);
        $weblingClient = new MockWeblingClientForUser('demo', 'key');
        
        // Use info-only-key which has only user_info permission
        $controller = new UserController(
            $this->db, $authorizer, $apiKeyManager,
            $this->config, 'info-only-key', $weblingClient, $this->encryptor
        );
        
        $session = createSessionWithToken($this->db, $this->encryptor->encrypt('789'), 'info-only-key');
        $this->db->validateSession($session['session_id']);
        
        $this->expectException(\iwhebAPI\SessionManagement\Auth\AuthorizationException::class);
        $controller->getId(['session_id' => $session['session_id']], []);
    }
}

class MockWeblingClientForUser extends \iwhebAPI\UserAuth\Http\WeblingClient {
    public function getUserDataById(int $userId): ?array {
        return [
            'id' => $userId,
            'firstName' => 'Test',
            'lastName' => 'User',
            'email' => 'test@example.com'
        ];
    }
}
