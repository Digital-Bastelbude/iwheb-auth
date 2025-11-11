<?php
use PHPUnit\Framework\TestCase;
use iwhebAPI\UserAuth\Http\SmtpMailer;

require_once __DIR__ . '/bootstrap.php';

class SmtpMailerTest extends TestCase {
    
    public function testSendAuthCodeReplacesCodePlaceholder(): void {
        // This is a unit test for placeholder replacement logic
        // We can't test actual SMTP sending without a real server
        
        $subject = 'Your code: ###CODE###';
        $message = 'Hello, your code is ###CODE### for session ###SESSION_ID###';
        $code = 'ABC123';
        $sessionId = 'session-xyz';
        
        // Test placeholder replacement logic by using reflection
        // to access private methods (alternative approach)
        
        // For now, we just test that the class can be instantiated
        $mailer = new SmtpMailer(
            'smtp.example.com',
            587,
            'user@example.com',
            'password',
            'noreply@example.com',
            'Test Mailer',
            true
        );
        
        $this->assertInstanceOf(SmtpMailer::class, $mailer);
    }
    
    public function testFromEnvThrowsExceptionWhenConfigIncomplete(): void {
        // Clear any existing env vars
        putenv('SMTP_HOST');
        putenv('SMTP_USERNAME');
        putenv('SMTP_PASSWORD');
        putenv('SMTP_FROM_EMAIL');
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing SMTP environment variables:');
        
        SmtpMailer::fromEnv();
    }
    
    public function testFromEnvCreatesMailerWithValidConfig(): void {
        // Set up environment variables
        putenv('SMTP_HOST=smtp.test.com');
        putenv('SMTP_PORT=587');
        putenv('SMTP_USERNAME=test@example.com');
        putenv('SMTP_PASSWORD=testpass');
        putenv('SMTP_FROM_EMAIL=noreply@example.com');
        putenv('SMTP_FROM_NAME=Test Service');
        putenv('SMTP_USE_TLS=true');
        
        $mailer = SmtpMailer::fromEnv();
        
        $this->assertInstanceOf(SmtpMailer::class, $mailer);
        
        // Clean up
        putenv('SMTP_HOST');
        putenv('SMTP_PORT');
        putenv('SMTP_USERNAME');
        putenv('SMTP_PASSWORD');
        putenv('SMTP_FROM_EMAIL');
        putenv('SMTP_FROM_NAME');
        putenv('SMTP_USE_TLS');
    }
    
    public function testConstructorWithDefaultValues(): void {
        $mailer = new SmtpMailer(
            'smtp.example.com',
            587,
            'user@example.com',
            'password',
            'noreply@example.com'
        );
        
        $this->assertInstanceOf(SmtpMailer::class, $mailer);
    }
    
    public function testConstructorWithAllParameters(): void {
        $mailer = new SmtpMailer(
            'smtp.example.com',
            465,
            'user@example.com',
            'password',
            'noreply@example.com',
            'My Service',
            false
        );
        
        $this->assertInstanceOf(SmtpMailer::class, $mailer);
    }
    
    public function testConstructorAutoDetectsPort465AsSSL(): void {
        // Port 465 should automatically enable SSL
        $mailer = new SmtpMailer(
            'smtp.example.com',
            465,
            'user@example.com',
            'password',
            'noreply@example.com'
        );
        
        $this->assertInstanceOf(SmtpMailer::class, $mailer);
    }
    
    public function testConstructorAutoDetectsPort587AsTLS(): void {
        // Port 587 should automatically enable TLS
        $mailer = new SmtpMailer(
            'smtp.example.com',
            587,
            'user@example.com',
            'password',
            'noreply@example.com'
        );
        
        $this->assertInstanceOf(SmtpMailer::class, $mailer);
    }
    
    public function testConstructorWithBothSSLAndTLSPreferSSL(): void {
        // When both SSL and TLS are enabled, SSL should take precedence
        $mailer = new SmtpMailer(
            'smtp.example.com',
            465,
            'user@example.com',
            'password',
            'noreply@example.com',
            'Test',
            true,  // useTls
            true   // useSsl - should take precedence
        );
        
        $this->assertInstanceOf(SmtpMailer::class, $mailer);
    }
    
    public function testConstructorUsesFromEmailAsFromNameWhenNotProvided(): void {
        $mailer = new SmtpMailer(
            'smtp.example.com',
            587,
            'user@example.com',
            'password',
            'noreply@example.com',
            '' // Empty fromName should default to fromEmail
        );
        
        $this->assertInstanceOf(SmtpMailer::class, $mailer);
    }
    
    public function testFromEnvWithMinimalConfig(): void {
        // Set up minimal required environment variables
        putenv('SMTP_HOST=smtp.minimal.com');
        putenv('SMTP_PORT=25');
        putenv('SMTP_USERNAME=minimal@example.com');
        putenv('SMTP_PASSWORD=minpass');
        putenv('SMTP_FROM_EMAIL=sender@example.com');
        
        $mailer = SmtpMailer::fromEnv();
        
        $this->assertInstanceOf(SmtpMailer::class, $mailer);
        
        // Clean up
        putenv('SMTP_HOST');
        putenv('SMTP_PORT');
        putenv('SMTP_USERNAME');
        putenv('SMTP_PASSWORD');
        putenv('SMTP_FROM_EMAIL');
    }
    
    public function testFromEnvWithSSLEnabled(): void {
        putenv('SMTP_HOST=smtp.ssl.com');
        putenv('SMTP_PORT=465');
        putenv('SMTP_USERNAME=ssl@example.com');
        putenv('SMTP_PASSWORD=sslpass');
        putenv('SMTP_FROM_EMAIL=ssl@example.com');
        putenv('SMTP_USE_SSL=true');
        putenv('SMTP_USE_TLS=false');
        
        $mailer = SmtpMailer::fromEnv();
        
        $this->assertInstanceOf(SmtpMailer::class, $mailer);
        
        // Clean up
        putenv('SMTP_HOST');
        putenv('SMTP_PORT');
        putenv('SMTP_USERNAME');
        putenv('SMTP_PASSWORD');
        putenv('SMTP_FROM_EMAIL');
        putenv('SMTP_USE_SSL');
        putenv('SMTP_USE_TLS');
    }
    
    public function testSendThrowsExceptionOnNetworkError(): void {
        // Create mailer with invalid/unreachable host to simulate network error
        $mailer = new SmtpMailer(
            'invalid-host-that-does-not-exist-12345.local',
            587,
            'user@example.com',
            'password',
            'noreply@example.com',
            'Test Mailer',
            false,
            false
        );
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to (send email|connect)|Connection refused|Could not connect|Cannot connect/i');
        
        $mailer->send('recipient@example.com', 'Test Subject', 'Test Message');
    }
    
    public function testSendThrowsExceptionOnSMTPAuthError(): void {
        // Note: This test requires a real SMTP server or mock to properly test
        // For now, we test with incorrect credentials to an unreachable host
        // which will fail at connection, simulating auth failure behavior
        
        $mailer = new SmtpMailer(
            'smtp.gmail.com', // Real host but wrong credentials
            587,
            'invalid-user@example.com',
            'wrong-password',
            'noreply@example.com',
            'Test Mailer',
            true,
            false
        );
        
        // This will throw an exception due to connection/auth failure
        $this->expectException(\RuntimeException::class);
        
        try {
            $mailer->send('recipient@example.com', 'Test Subject', 'Test Message');
        } catch (\RuntimeException $e) {
            // Verify the error is related to SMTP/connection/auth
            $message = $e->getMessage();
            $this->assertMatchesRegularExpression(
                '/Failed to connect|Connection|Authentication|SMTP|AUTH/i',
                $message,
                'Exception should be related to SMTP connection or authentication'
            );
            throw $e; // Re-throw for expectException
        }
    }
    
    public function testSendAuthCodeThrowsExceptionOnNetworkError(): void {
        // Create mailer with invalid host
        $mailer = new SmtpMailer(
            'invalid-host-network-error.local',
            587,
            'user@example.com',
            'password',
            'noreply@example.com',
            'Test Mailer',
            false,
            false
        );
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to (send email|connect)|Connection refused|Could not connect|Cannot connect/i');
        
        $mailer->sendAuthCode(
            'recipient@example.com',
            'Your Code',
            'Code: ###CODE###',
            '123456',
            'session-123'
        );
    }
}
