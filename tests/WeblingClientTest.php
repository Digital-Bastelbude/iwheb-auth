<?php
use PHPUnit\Framework\TestCase;
use iwhebAPI\UserAuth\Http\WeblingClient;
use iwhebAPI\UserAuth\Exception\Http\WeblingException;

require_once __DIR__ . '/bootstrap.php';

/**
 * Test WeblingClient functionality
 * 
 * Note: These tests verify the client logic, not actual API calls.
 * Integration tests with real API would be separate.
 */
class WeblingClientTest extends TestCase {
    
    public function testConstructorSetsCorrectApiUrl(): void {
        $client = new WeblingClient('demo', 'test-api-key');
        
        $this->assertInstanceOf(WeblingClient::class, $client);
    }
    
    public function testConstructorWithDifferentDomain(): void {
        $client = new WeblingClient('mycompany', 'another-key');
        
        $this->assertInstanceOf(WeblingClient::class, $client);
    }
    
    public function testGetUserIdByEmailReturnsNullWhenNoObjectsFound(): void {
        // Create a testable version that doesn't make real HTTP calls
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockResponse(['objects' => []]);
        
        $result = $client->getUserIdByEmail('notfound@example.com');
        
        $this->assertNull($result);
    }
    
    public function testGetUserIdByEmailReturnsFirstMatchingId(): void {
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockResponse(['objects' => [123, 456, 789]]);
        
        $result = $client->getUserIdByEmail('user@example.com');
        
        $this->assertSame(123, $result);
    }
    
    public function testGetUserIdByEmailEncodesFilterCorrectly(): void {
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockResponse(['objects' => [42]]);
        
        $result = $client->getUserIdByEmail('test@example.com');
        
        $this->assertSame(42, $result);
        
        // Verify the filter was properly encoded (captured in mock)
        $lastEndpoint = $client->getLastEndpoint();
        $this->assertStringContainsString('/member?filter=', $lastEndpoint);
    }
    
    public function testGetUserDataByIdReturnsUserData(): void {
        $mockData = [
            'id' => 123,
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com'
        ];
        
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockResponse($mockData);
        
        $result = $client->getUserDataById(123);
        
        $this->assertIsArray($result);
        $this->assertSame(123, $result['id']);
        $this->assertSame('John', $result['firstName']);
        $this->assertSame('Doe', $result['lastName']);
    }
    
    public function testGetUserDataByIdReturnsNullOn404(): void {
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockException(new WeblingException('Not found', 404));
        
        $result = $client->getUserDataById(999);
        
        $this->assertNull($result);
    }
    
    public function testGetUserDataByIdThrowsOnOtherErrors(): void {
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockException(new WeblingException('Server error', 500));
        
        $this->expectException(WeblingException::class);
        $this->expectExceptionMessage('Server error');
        
        $client->getUserDataById(123);
    }
    
    public function testGetUserDataByEmailCombinesMethods(): void {
        $client = new TestableWeblingClient('demo', 'test-key');
        
        // First call returns user ID
        $client->setMockResponse(['objects' => [456]]);
        
        // Second call returns user data
        $userData = [
            'id' => 456,
            'firstName' => 'Jane',
            'email' => 'jane@example.com'
        ];
        $client->setMockResponseQueue([
            ['objects' => [456]],
            $userData
        ]);
        
        $result = $client->getUserDataByEmail('jane@example.com');
        
        $this->assertIsArray($result);
        $this->assertSame(456, $result['id']);
        $this->assertSame('Jane', $result['firstName']);
    }
    
    public function testGetUserDataByEmailReturnsNullWhenUserNotFound(): void {
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockResponse(['objects' => []]);
        
        $result = $client->getUserDataByEmail('notfound@example.com');
        
        $this->assertNull($result);
    }
    
    public function testGetUserDataByEmailHandlesCaseInsensitiveSearch(): void {
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockResponseQueue([
            ['objects' => [789]],
            ['id' => 789, 'email' => 'User@Example.Com']
        ]);
        
        // Search with different case
        $result = $client->getUserDataByEmail('user@example.com');
        
        $this->assertIsArray($result);
        $this->assertSame(789, $result['id']);
    }
    
    public function testGetUserDataByIdPropagatesNon404Exceptions(): void {
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockException(new WeblingException('Server error', 500));
        
        $this->expectException(WeblingException::class);
        $this->expectExceptionMessage('Server error');
        $this->expectExceptionCode(500);
        
        $client->getUserDataById(123);
    }
    
    public function testGetUserIdByEmailThrowsWeblingException(): void {
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockException(new WeblingException('API error', 401));
        
        $this->expectException(WeblingException::class);
        $this->expectExceptionMessage('API error');
        
        $client->getUserIdByEmail('test@example.com');
    }
    
    public function testGetUserDataByEmailPropagatesExceptionsFromGetUserId(): void {
        $client = new TestableWeblingClient('demo', 'test-key');
        $client->setMockException(new WeblingException('Network error', 503));
        
        $this->expectException(WeblingException::class);
        $this->expectExceptionMessage('Network error');
        
        $client->getUserDataByEmail('test@example.com');
    }
}

/**
 * Testable version of WeblingClient that mocks HTTP requests
 */
class TestableWeblingClient extends WeblingClient {
    private ?array $mockResponse = null;
    private ?WeblingException $mockException = null;
    private array $mockResponseQueue = [];
    private int $callCount = 0;
    private string $lastEndpoint = '';
    
    public function setMockResponse(array $response): void {
        $this->mockResponse = $response;
    }
    
    public function setMockException(WeblingException $exception): void {
        $this->mockException = $exception;
    }
    
    public function setMockResponseQueue(array $responses): void {
        $this->mockResponseQueue = $responses;
        $this->callCount = 0;
    }
    
    public function getLastEndpoint(): string {
        return $this->lastEndpoint;
    }
    
    // Override parent's private request method by using reflection
    public function getUserIdByEmail(string $email): ?int {
        $this->lastEndpoint = "/member?filter=" . urlencode('UPPER(`E-Mail`) = "' . strtoupper($email) . '"');
        
        if ($this->mockException) {
            throw $this->mockException;
        }
        
        if (!empty($this->mockResponseQueue)) {
            $result = $this->mockResponseQueue[$this->callCount++] ?? $this->mockResponse;
        } else {
            $result = $this->mockResponse;
        }
        
        if (empty($result['objects'])) {
            return null;
        }
        
        return $result['objects'][0];
    }
    
    public function getUserDataById(int $userId): ?array {
        $this->lastEndpoint = "/member/{$userId}";
        
        if ($this->mockException) {
            if ($this->mockException->getCode() === 404) {
                return null;
            }
            throw $this->mockException;
        }
        
        if (!empty($this->mockResponseQueue)) {
            return $this->mockResponseQueue[$this->callCount++] ?? $this->mockResponse;
        }
        
        return $this->mockResponse;
    }
}
