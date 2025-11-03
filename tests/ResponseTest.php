<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Http\Response;

require_once __DIR__ . '/bootstrap.php';

/**
 * Test Response class functionality
 * 
 * Note: sendJson() and notFound() call exit(), so we can't test them directly
 * in unit tests. We test readJsonBody() which doesn't exit.
 */
class ResponseTest extends TestCase {
    
    public function testConstructorWithDefaultLogger(): void {
        $response = new Response();
        
        $this->assertInstanceOf(Response::class, $response);
    }
    
    public function testConstructorWithCustomLogger(): void {
        $customLogger = \Logger::getInstance();
        $response = new Response($customLogger);
        
        $this->assertInstanceOf(Response::class, $response);
    }
    
    public function testConstructorWithCustomInputReader(): void {
        $customReader = function(): string {
            return '{"test": "data"}';
        };
        
        $response = new Response(null, $customReader);
        
        $this->assertInstanceOf(Response::class, $response);
    }
    
    public function testReadJsonBodyWithValidJson(): void {
        $jsonData = '{"name": "John", "age": 30, "active": true}';
        
        $response = new Response(null, fn() => $jsonData);
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertSame('John', $result['name']);
        $this->assertSame(30, $result['age']);
        $this->assertTrue($result['active']);
    }
    
    public function testReadJsonBodyWithEmptyString(): void {
        $response = new Response(null, fn() => '');
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    public function testReadJsonBodyWithInvalidJson(): void {
        $invalidJson = '{invalid json}';
        
        $response = new Response(null, fn() => $invalidJson);
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    public function testReadJsonBodyWithNullReturn(): void {
        // Simulate file_get_contents returning false/null
        $response = new Response(null, fn() => '');
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    public function testReadJsonBodyWithNestedStructure(): void {
        $complexJson = json_encode([
            'user' => [
                'id' => 123,
                'profile' => [
                    'email' => 'test@example.com',
                    'tags' => ['admin', 'verified']
                ]
            ],
            'metadata' => [
                'timestamp' => 1699000000,
                'version' => '2.0'
            ]
        ]);
        
        $response = new Response(null, fn() => $complexJson);
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertSame(123, $result['user']['id']);
        $this->assertSame('test@example.com', $result['user']['profile']['email']);
        $this->assertIsArray($result['user']['profile']['tags']);
        $this->assertContains('admin', $result['user']['profile']['tags']);
    }
    
    public function testReadJsonBodyWithUnicodeCharacters(): void {
        $unicodeJson = '{"message": "Hëllö Wörld 🌍", "emoji": "👍"}';
        
        $response = new Response(null, fn() => $unicodeJson);
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertSame('Hëllö Wörld 🌍', $result['message']);
        $this->assertSame('👍', $result['emoji']);
    }
    
    public function testReadJsonBodyWithNumericArray(): void {
        $arrayJson = '[1, 2, 3, 4, 5]';
        
        $response = new Response(null, fn() => $arrayJson);
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertSame([1, 2, 3, 4, 5], $result);
    }
    
    public function testReadJsonBodyReturnsEmptyArrayForNonArrayJson(): void {
        // JSON that decodes to a string, not array
        $stringJson = '"just a string"';
        
        $response = new Response(null, fn() => $stringJson);
        
        $result = $response->readJsonBody();
        
        // Should return empty array since result is not an array
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    public function testReadJsonBodyReturnsEmptyArrayForNumberJson(): void {
        // JSON that decodes to a number
        $numberJson = '42';
        
        $response = new Response(null, fn() => $numberJson);
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    public function testReadJsonBodyReturnsEmptyArrayForBooleanJson(): void {
        // JSON that decodes to boolean
        $boolJson = 'true';
        
        $response = new Response(null, fn() => $boolJson);
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    public function testReadJsonBodyWithEmptyObject(): void {
        $emptyObjectJson = '{}';
        
        $response = new Response(null, fn() => $emptyObjectJson);
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
    
    public function testReadJsonBodyWithEmptyArray(): void {
        $emptyArrayJson = '[]';
        
        $response = new Response(null, fn() => $emptyArrayJson);
        
        $result = $response->readJsonBody();
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
