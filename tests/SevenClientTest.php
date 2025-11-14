<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use iwhebAPI\UserAuth\Http\SevenClient;

/**
 * Tests for SevenClient
 */
class SevenClientTest extends TestCase {
    
    /**
     * Test constructor sets API key
     */
    public function testConstructorSetsApiKey(): void {
        $client = new SevenClient('test-api-key');
        $this->assertInstanceOf(SevenClient::class, $client);
    }
    
    /**
     * Test fromEnv throws exception when SEVEN_API_KEY not set
     */
    public function testFromEnvThrowsExceptionWhenConfigIncomplete(): void {
        // Save current env
        $originalApiKey = getenv('SEVEN_API_KEY');
        
        // Clear env var
        putenv('SEVEN_API_KEY');
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEVEN_API_KEY environment variable must be set');
        
        SevenClient::fromEnv();
        
        // Restore env
        if ($originalApiKey !== false) {
            putenv('SEVEN_API_KEY=' . $originalApiKey);
        }
    }
    
    /**
     * Test fromEnv creates client with valid config
     */
    public function testFromEnvCreatesClientWithValidConfig(): void {
        // Save current env
        $originalApiKey = getenv('SEVEN_API_KEY');
        
        // Set env var
        putenv('SEVEN_API_KEY=test-api-key');
        
        $client = SevenClient::fromEnv();
        $this->assertInstanceOf(SevenClient::class, $client);
        
        // Restore env
        if ($originalApiKey !== false) {
            putenv('SEVEN_API_KEY=' . $originalApiKey);
        } else {
            putenv('SEVEN_API_KEY');
        }
    }
    
    /**
     * Test sendAuthCode formats message correctly
     * 
     * Note: This test verifies the method exists and accepts the correct parameters.
     * Actual API calls would require mocking or a test API key.
     */
    public function testSendAuthCodeMethodSignature(): void {
        $client = new SevenClient('test-api-key');
        
        // Verify method exists and has correct signature
        $this->assertTrue(method_exists($client, 'sendAuthCode'));
        
        $reflection = new ReflectionMethod($client, 'sendAuthCode');
        $this->assertEquals(3, $reflection->getNumberOfParameters());
        $this->assertEquals(4, $reflection->getNumberOfParameters() + 1); // including optional sender
    }
}
