<?php
use PHPUnit\Framework\TestCase;
use iwhebAPI\SessionManagement\{Database, UidEncryptor};
use iwhebAPI\SessionManagement\{Authorizer, ApiKeyManager};
use iwhebAPI\SessionManagement\Http\Response;
use iwhebAPI\SessionManagement\Exception\InvalidSessionException;

use IwhebAPI\UserAuth\Http\Controllers\AuthController;
use IwhebAPI\UserAuth\Http\WeblingClient;
use IwhebAPI\UserAuth\Exception\{UserNotFoundException, InvalidCodeException};
use IwhebAPI\UserAuth\Exception\Http\InvalidInputException;

require_once __DIR__ . '/bootstrap.php';

class AuthControllerTest extends TestCase {
    private Database $db;
    private string $dbFile;
    private AuthController $controller;
    private UidEncryptor $encryptor;
    private array $config;
    private string $apiKey;
    
    protected function setUp(): void {
        $this->dbFile = sys_get_temp_dir() . '/auth_controller_test_' . bin2hex(random_bytes(6)) . '.db';
        $this->db = new Database($this->dbFile);
        
        $this->config = [
            'keys' => [
                'test-key' => [
                    'name' => 'Test App',
                    'permissions' => ['user_info', 'user_token']
                ]
            ],
            'rate_limit' => [
                'default' => [
                    'window_seconds' => 60,
                    'max_requests' => 100
                ]
            ],
            'email' => [
                'login_code' => [
                    'subject' => 'Your Code: ###CODE###',
                    'message' => 'Code: ###CODE###',
                    'link_block' => ''
                ]
            ]
        ];
        
        $this->apiKey = 'test-key';
        $this->encryptor = new UidEncryptor(UidEncryptor::generateKey());
        
        $response = new Response();
        $authorizer = new Authorizer($this->config);
        $apiKeyManager = new ApiKeyManager($this->config['keys']);
        $weblingClient = new MockWeblingClient('demo', 'api-key');
        
        $this->controller = new AuthController(
            $this->db,
            $response,
            $authorizer,
            $apiKeyManager,
            $this->config,
            $this->apiKey,
            $weblingClient,
            $this->encryptor
        );
    }
    
    protected function tearDown(): void {
        if (file_exists($this->dbFile)) @unlink($this->dbFile);
        $walFile = $this->dbFile . '-wal';
        $shmFile = $this->dbFile . '-shm';
        if (file_exists($walFile)) @unlink($walFile);
        if (file_exists($shmFile)) @unlink($shmFile);
    }
    
    public function testLoginThrowsWhenEmailMissing(): void {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Email required');
        
        $this->controller->login([], []);
    }
    
    public function testLoginThrowsWhenEmailEncodingInvalid(): void {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Invalid email encoding');
        
        $this->controller->login([], ['email' => 'not-valid-base64!!!']);
    }
    
    public function testLoginThrowsWhenUserNotFoundInWebling(): void {
        // MockWeblingClient returns null for non-existent users
        $email = base64_encode('notfound@example.com');
        
        $this->expectException(UserNotFoundException::class);
        
        $this->controller->login([], ['email' => $email]);
    }
    
    public function testValidateThrowsWhenCodeMissing(): void {
        // Create user and session first
        // User creation removed - using token directly: 'token123'
        $session = createSessionWithToken($this->db, 'token123', $this->apiKey);
        
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('code required');
        
        $this->controller->validate(['session_id' => $session['session_id']], []);
    }
    
    public function testValidateThrowsWhenSessionNotFound(): void {
        $this->expectException(InvalidSessionException::class);
        
        $this->controller->validate(['session_id' => 'nonexistent'], ['code' => '123456']);
    }
    
    public function testValidateThrowsWhenWrongApiKey(): void {
        // Create session with different API key
        // User creation removed - using token directly: 'token456'
        $session = createSessionWithToken($this->db, 'token456', 'other-key');
        
        $this->expectException(InvalidSessionException::class);
        
        $this->controller->validate(['session_id' => $session['session_id']], ['code' => '123456']);
    }
    
    public function testValidateThrowsWhenCodeInvalid(): void {
        // User creation removed - using token directly: 'token789'
        $session = createSessionWithToken($this->db, 'token789', $this->apiKey);
        
        $this->expectException(InvalidCodeException::class);
        
        // Wrong code
        $this->controller->validate(['session_id' => $session['session_id']], ['code' => '000000']);
    }
    
    public function testValidateSuccessWithCorrectCode(): void {
        // User creation removed - using token directly: 'token999'
        $session = createSessionWithToken($this->db, 'token999', $this->apiKey);
        $correctCode = $session['code'];
        
        $result = $this->controller->validate(
            ['session_id' => $session['session_id']], 
            ['code' => $correctCode]
        );
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('session_id', $result['data']);
        $this->assertTrue($result['data']['validated']);
        
        // New session ID should be different
        $this->assertNotSame($session['session_id'], $result['data']['session_id']);
    }
    
    public function testValidateCreatesNewSessionAndMarksValidated(): void {
        // User creation removed - using token directly: 'token-validate'
        $session = createSessionWithToken($this->db, 'token-validate', $this->apiKey);
        
        $result = $this->controller->validate(
            ['session_id' => $session['session_id']], 
            ['code' => $session['code']]
        );
        
        $newSessionId = $result['data']['session_id'];
        
        // Verify new session is marked as validated
        $isValidated = $this->db->isSessionValidated($newSessionId);
        $this->assertTrue($isValidated);
    }
    
    public function testLogoutDeletesSession(): void {
        // User creation removed - using token directly: 'token-logout'
        $session = createSessionWithToken($this->db, 'token-logout', $this->apiKey);
        
        $result = $this->controller->logout(['session_id' => $session['session_id']], []);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame('Logged out successfully', $result['data']['message']);
        
        // Verify session is gone
        $deletedSession = $this->db->getSessionBySessionId($session['session_id']);
        $this->assertNull($deletedSession);
    }
    
    public function testLogoutThrowsWhenSessionNotFound(): void {
        $this->expectException(InvalidSessionException::class);
        
        $this->controller->logout(['session_id' => 'nonexistent-session'], []);
    }
    
    public function testLogoutThrowsWhenWrongApiKey(): void {
        // User creation removed - using token directly: 'token-logout-wrong'
        $session = createSessionWithToken($this->db, 'token-logout-wrong', 'other-key');
        
        $this->expectException(InvalidSessionException::class);
        
        $this->controller->logout(['session_id' => $session['session_id']], []);
    }
}

/**
 * Mock WeblingClient for testing without real API calls
 */
class MockWeblingClient extends WeblingClient {
    public function getUserIdByEmail(string $email): ?int {
        // Return null for non-existent users
        if (strpos($email, 'notfound') !== false) {
            return null;
        }
        
        // Return a mock user ID for valid emails
        return 12345;
    }
    
    public function getUserDataById(int $userId): ?array {
        if ($userId === 12345) {
            return [
                'id' => 12345,
                'firstName' => 'Test',
                'lastName' => 'User',
                'email' => 'test@example.com'
            ];
        }
        
        return null;
    }
    
    public function getUserDataByEmail(string $email): ?array {
        $userId = $this->getUserIdByEmail($email);
        
        if ($userId === null) {
            return null;
        }
        
        return $this->getUserDataById($userId);
    }
}
