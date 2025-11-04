<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class LoggerTest extends TestCase {
    private string $testLogFile;
    
    protected function setUp(): void {
        // Create a temp log file for each test
        $this->testLogFile = sys_get_temp_dir() . '/logger_test_' . bin2hex(random_bytes(6)) . '.log';
        
        // Reset singleton before each test
        Logger::resetInstance();
    }
    
    protected function tearDown(): void {
        // Clean up log file
        if (file_exists($this->testLogFile)) {
            @unlink($this->testLogFile);
        }
        
        // Clean up log directory if empty
        $logDir = dirname($this->testLogFile);
        if (is_dir($logDir) && count(scandir($logDir)) === 2) {
            @rmdir($logDir);
        }
        
        Logger::resetInstance();
    }
    
    public function testGetInstanceCreatesNewInstance(): void {
        $logger = Logger::getInstance($this->testLogFile);
        
        $this->assertInstanceOf(Logger::class, $logger);
    }
    
    public function testGetInstanceReturnsSameInstance(): void {
        $logger1 = Logger::getInstance($this->testLogFile);
        $logger2 = Logger::getInstance();
        
        $this->assertSame($logger1, $logger2);
    }
    
    public function testResetInstanceClearsSharedInstance(): void {
        $logger1 = Logger::getInstance($this->testLogFile);
        Logger::resetInstance();
        $logger2 = Logger::getInstance($this->testLogFile);
        
        $this->assertNotSame($logger1, $logger2);
    }
    
    public function testLogAccessWritesToFile(): void {
        $logger = Logger::getInstance(
            $this->testLogFile,
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test', 'REMOTE_ADDR' => '127.0.0.1'],
            []
        );
        
        $logger->logAccess(200, 'ALLOW', 'OK', 'test-api-key');
        
        $this->assertFileExists($this->testLogFile);
        
        $content = file_get_contents($this->testLogFile);
        $this->assertNotEmpty($content);
        
        $logEntry = json_decode(trim($content), true);
        $this->assertIsArray($logEntry);
        $this->assertSame(200, $logEntry['status']);
        $this->assertSame('ALLOW', $logEntry['outcome']);
        $this->assertSame('OK', $logEntry['reason']);
        $this->assertSame('test-api-key', $logEntry['key']);
        $this->assertSame('GET', $logEntry['method']);
        $this->assertSame('/test', $logEntry['path']);
        $this->assertSame('127.0.0.1', $logEntry['ip']);
    }
    
    public function testLogAccessWithNullKeyLogsNone(): void {
        $logger = Logger::getInstance(
            $this->testLogFile,
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/test', 'REMOTE_ADDR' => '192.168.1.1'],
            []
        );
        
        $logger->logAccess(403, 'DENY', 'NO_KEY', null);
        
        $content = file_get_contents($this->testLogFile);
        $logEntry = json_decode(trim($content), true);
        
        $this->assertSame('(none)', $logEntry['key']);
        $this->assertSame(403, $logEntry['status']);
        $this->assertSame('DENY', $logEntry['outcome']);
    }
    
    public function testLogAccessHandlesXForwardedFor(): void {
        $logger = Logger::getInstance(
            $this->testLogFile,
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/test',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.1, 198.51.100.1',
                'REMOTE_ADDR' => '192.168.1.1'
            ],
            []
        );
        
        $logger->logAccess(200, 'ALLOW', 'OK', 'key');
        
        $content = file_get_contents($this->testLogFile);
        $logEntry = json_decode(trim($content), true);
        
        // Should use first IP from X-Forwarded-For
        $this->assertSame('203.0.113.1', $logEntry['ip']);
    }
    
    public function testLogAccessCreatesDirectoryIfNotExists(): void {
        $nestedLogFile = sys_get_temp_dir() . '/test_logs_' . bin2hex(random_bytes(4)) . '/subdir/test.log';
        
        $logger = Logger::getInstance(
            $nestedLogFile,
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REMOTE_ADDR' => '127.0.0.1'],
            []
        );
        
        $logger->logAccess(200, 'ALLOW', 'OK', 'key');
        
        $this->assertFileExists($nestedLogFile);
        
        // Cleanup
        @unlink($nestedLogFile);
        @rmdir(dirname($nestedLogFile));
        @rmdir(dirname(dirname($nestedLogFile)));
        
        Logger::resetInstance();
    }
    
    public function testLogAccessWithQueryParameters(): void {
        $logger = Logger::getInstance(
            $this->testLogFile,
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/test?foo=bar&baz=qux',
                'REMOTE_ADDR' => '127.0.0.1'
            ],
            []
        );
        
        $logger->logAccess(200, 'ALLOW', 'OK', 'key');
        
        $content = file_get_contents($this->testLogFile);
        $logEntry = json_decode(trim($content), true);
        
        // Path should not include query parameters
        $this->assertSame('/api/test', $logEntry['path']);
    }
    
    public function testLogAccessHandlesMissingServerVariables(): void {
        $logger = Logger::getInstance(
            $this->testLogFile,
            [], // Empty server array
            []
        );
        
        $logger->logAccess(500, 'DENY', 'ERROR', null);
        
        $content = file_get_contents($this->testLogFile);
        $logEntry = json_decode(trim($content), true);
        
        $this->assertSame('GET', $logEntry['method']); // Default
        $this->assertSame('/', $logEntry['path']); // Default
        $this->assertSame('0.0.0.0', $logEntry['ip']); // Default
    }
    
    public function testLogAccessWritesMultipleEntries(): void {
        $logger = Logger::getInstance(
            $this->testLogFile,
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test', 'REMOTE_ADDR' => '127.0.0.1'],
            []
        );
        
        $logger->logAccess(200, 'ALLOW', 'OK', 'key1');
        $logger->logAccess(404, 'DENY', 'NOT_FOUND', 'key2');
        $logger->logAccess(201, 'ALLOW', 'CREATED', 'key3');
        
        $content = file_get_contents($this->testLogFile);
        $lines = explode("\n", trim($content));
        
        $this->assertCount(3, $lines);
        
        $entry1 = json_decode($lines[0], true);
        $entry2 = json_decode($lines[1], true);
        $entry3 = json_decode($lines[2], true);
        
        $this->assertSame('key1', $entry1['key']);
        $this->assertSame('key2', $entry2['key']);
        $this->assertSame('key3', $entry3['key']);
    }
}
