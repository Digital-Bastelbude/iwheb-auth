<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use iwhebAPI\UserAuth\Validation\{ValidationProviderManager, EmailValidationProvider, SmsValidationProvider};
use iwhebAPI\UserAuth\Http\{WeblingClient, SevenClient};

/**
 * Tests for Validation Providers
 */
class ValidationProviderTest extends TestCase {
    
    private WeblingClient $weblingClient;
    private SevenClient $sevenClient;
    
    protected function setUp(): void {
        // Create mock Webling client
        $this->weblingClient = $this->createMock(WeblingClient::class);
        $this->sevenClient = $this->createMock(SevenClient::class);
    }
    
    /**
     * Test ValidationProviderManager registers providers
     */
    public function testValidationProviderManagerRegistersProviders(): void {
        $manager = new ValidationProviderManager();
        $emailProvider = new EmailValidationProvider($this->weblingClient);
        
        $manager->register($emailProvider);
        
        $this->assertTrue($manager->hasProvider('email'));
        $this->assertSame($emailProvider, $manager->getProvider('email'));
    }
    
    /**
     * Test ValidationProviderManager returns null for unknown provider
     */
    public function testValidationProviderManagerReturnsNullForUnknownProvider(): void {
        $manager = new ValidationProviderManager();
        
        $this->assertNull($manager->getProvider('unknown'));
    }
    
    /**
     * Test ValidationProviderManager gets default provider
     */
    public function testValidationProviderManagerGetsDefaultProvider(): void {
        $manager = new ValidationProviderManager();
        $emailProvider = new EmailValidationProvider($this->weblingClient);
        
        $manager->register($emailProvider);
        
        $this->assertSame($emailProvider, $manager->getDefaultProvider());
        $this->assertSame($emailProvider, $manager->getProvider(null));
    }
    
    /**
     * Test ValidationProviderManager throws exception when default not registered
     */
    public function testValidationProviderManagerThrowsWhenDefaultNotRegistered(): void {
        $manager = new ValidationProviderManager();
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Default validation provider 'email' is not registered");
        
        $manager->getDefaultProvider();
    }
    
    /**
     * Test ValidationProviderManager sets custom default provider
     */
    public function testValidationProviderManagerSetsCustomDefaultProvider(): void {
        $manager = new ValidationProviderManager();
        $emailProvider = new EmailValidationProvider($this->weblingClient);
        $smsProvider = new SmsValidationProvider($this->weblingClient, $this->sevenClient);
        
        $manager->register($emailProvider);
        $manager->register($smsProvider);
        $manager->setDefaultProvider('sms');
        
        $this->assertSame($smsProvider, $manager->getDefaultProvider());
    }
    
    /**
     * Test EmailValidationProvider has correct name
     */
    public function testEmailValidationProviderHasCorrectName(): void {
        $provider = new EmailValidationProvider($this->weblingClient);
        $this->assertEquals('email', $provider->getName());
    }
    
    /**
     * Test SmsValidationProvider has correct name
     */
    public function testSmsValidationProviderHasCorrectName(): void {
        $provider = new SmsValidationProvider($this->weblingClient, $this->sevenClient);
        $this->assertEquals('sms', $provider->getName());
    }
    
    /**
     * Test EmailValidationProvider getUserId calls WeblingClient
     */
    public function testEmailValidationProviderGetUserIdCallsWeblingClient(): void {
        $this->weblingClient->expects($this->once())
            ->method('getUserIdByEmail')
            ->with('test@example.com')
            ->willReturn(123);
        
        $provider = new EmailValidationProvider($this->weblingClient);
        $userId = $provider->getUserId('test@example.com');
        
        $this->assertEquals(123, $userId);
    }
    
    /**
     * Test SmsValidationProvider getUserId calls WeblingClient
     */
    public function testSmsValidationProviderGetUserIdCallsWeblingClient(): void {
        $this->weblingClient->expects($this->once())
            ->method('getUserIdByPhone')
            ->with('+41123456789', 'Telefon 1')
            ->willReturn(456);
        
        $provider = new SmsValidationProvider($this->weblingClient, $this->sevenClient);
        $userId = $provider->getUserId('+41123456789');
        
        $this->assertEquals(456, $userId);
    }
    
    /**
     * Test ValidationProviderManager lists all provider names
     */
    public function testValidationProviderManagerListsAllProviderNames(): void {
        $manager = new ValidationProviderManager();
        $emailProvider = new EmailValidationProvider($this->weblingClient);
        $smsProvider = new SmsValidationProvider($this->weblingClient, $this->sevenClient);
        
        $manager->register($emailProvider);
        $manager->register($smsProvider);
        
        $names = $manager->getProviderNames();
        
        $this->assertCount(2, $names);
        $this->assertContains('email', $names);
        $this->assertContains('sms', $names);
    }
    
    /**
     * Test EmailValidationProvider selectRecipient returns email from properties
     */
    public function testEmailValidationProviderSelectRecipientReturnsEmail(): void {
        $provider = new EmailValidationProvider($this->weblingClient);
        $userProperties = [
            'E-Mail' => 'test@example.com',
            'Telefon 1' => '+41123456789',
            'Name' => 'Test User'
        ];
        
        $recipient = $provider->selectRecipient($userProperties);
        
        $this->assertEquals('test@example.com', $recipient);
    }
    
    /**
     * Test EmailValidationProvider selectRecipient returns null when no email
     */
    public function testEmailValidationProviderSelectRecipientReturnsNullWhenNoEmail(): void {
        $provider = new EmailValidationProvider($this->weblingClient);
        $userProperties = [
            'Telefon 1' => '+41123456789',
            'Name' => 'Test User'
        ];
        
        $recipient = $provider->selectRecipient($userProperties);
        
        $this->assertNull($recipient);
    }
    
    /**
     * Test SmsValidationProvider selectRecipient returns mobile from properties
     */
    public function testSmsValidationProviderSelectRecipientReturnsMobile(): void {
        $provider = new SmsValidationProvider($this->weblingClient, $this->sevenClient);
        $userProperties = [
            'E-Mail' => 'test@example.com',
            'Telefon 1' => '+41123456789',
            'Name' => 'Test User'
        ];
        
        $recipient = $provider->selectRecipient($userProperties);
        
        $this->assertEquals('+41123456789', $recipient);
    }
    
    /**
     * Test SmsValidationProvider selectRecipient returns null when no phone
     */
    public function testSmsValidationProviderSelectRecipientReturnsNullWhenNoPhone(): void {
        $provider = new SmsValidationProvider($this->weblingClient, $this->sevenClient);
        $userProperties = [
            'E-Mail' => 'test@example.com',
            'Name' => 'Test User'
        ];
        
        $recipient = $provider->selectRecipient($userProperties);
        
        $this->assertNull($recipient);
    }
    
    /**
     * Test SmsValidationProvider selectRecipient uses custom phone field as fallback
     */
    public function testSmsValidationProviderSelectRecipientUsesCustomPhoneFieldAsFallback(): void {
        $provider = new SmsValidationProvider($this->weblingClient, $this->sevenClient, 'Telefon 2');
        $userProperties = [
            'E-Mail' => 'test@example.com',
            'Telefon 2' => '+41987654321',
            'Name' => 'Test User'
        ];
        
        $recipient = $provider->selectRecipient($userProperties);
        
        $this->assertEquals('+41987654321', $recipient);
    }
}
