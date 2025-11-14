# SMS Validation with Seven.io

This document describes how to configure and use SMS validation for authentication codes.

## Overview

The authentication service supports sending validation codes via SMS using the [Seven.io](https://www.seven.io/) SMS gateway. This is an alternative to email validation and can be selected on a per-request basis.

## Features

- **Multiple validation providers**: Choose between email and SMS
- **Automatic fallback**: Falls back to email if SMS fails (when email is provided)
- **Pluggable architecture**: Easy to add additional validation providers
- **Phone number lookup**: Automatically finds users by phone number in Webling

## Configuration

### 1. Get Seven.io API Key

1. Sign up for a Seven.io account at https://app.seven.io/
2. Navigate to your API settings
3. Copy your API key

### 2. Configure Credentials

Add your Seven.io credentials to `config/.secrets.php`:

```php
// Seven.io API Key for sending SMS messages
putenv('SEVEN_API_KEY=your-seven-api-key-here');

// Seven.io Sender Name (optional, max 11 alphanumeric characters)
putenv('SEVEN_SENDER_NAME=AuthService');
```

### 3. Verify Configuration

The SMS validation provider is automatically registered if the Seven.io API key is configured. You can verify this by checking the application logs during startup.

## Usage

### Email Validation (Default)

Request with email (default behavior):

```bash
curl -X POST https://your-domain.com/login \
  -H "X-API-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "dXNlckBleGFtcGxlLmNvbQ=="
  }'
```

### SMS Validation

Request with phone number and SMS provider:

```bash
# Phone number in international format: +41123456789
# Base64 encoded: KzQxMTIzNDU2Nzg5

curl -X POST https://your-domain.com/login \
  -H "X-API-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "KzQxMTIzNDU2Nzg5",
    "provider": "sms"
  }'
```

### SMS with Email Fallback

Request with both phone and email (SMS with email fallback):

```bash
curl -X POST https://your-domain.com/login \
  -H "X-API-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "KzQxMTIzNDU2Nzg5",
    "email": "dXNlckBleGFtcGxlLmNvbQ==",
    "provider": "sms"
  }'
```

In this case:
- First tries to send SMS to the phone number
- If SMS fails, automatically falls back to email
- If phone lookup fails, tries to find user by email

## Phone Number Format

Phone numbers must be in international format starting with `+`:

- ✅ Valid: `+41123456789`, `+491234567890`
- ❌ Invalid: `0123456789`, `123-456-7890`

Phone numbers are normalized for comparison, so different formats are matched (e.g., `+41 12 345 67 89` matches `+41123456789`).

## Webling Integration

### Phone Field Configuration

By default, the SMS provider looks up users by the `Telefon 1` field in Webling. You can customize this field name when creating the provider:

```php
$smsProvider = new SmsValidationProvider($weblingClient, $sevenClient, 'Telefon 2');
```

### User Lookup

The SMS provider searches for users in Webling using the following process:

1. Normalize the phone number (remove spaces, dashes, etc.)
2. Search Webling for users with matching phone numbers
3. Compare normalized phone numbers for exact match
4. Return the first matching user ID

## Error Handling

### SMS Sending Fails

If SMS sending fails and an email address is provided in the request, the system automatically falls back to email validation.

### User Not Found

If the user is not found by phone number and an email is provided, the system tries to find the user by email.

### Seven.io API Errors

Common Seven.io API errors and solutions:

- **Invalid API key**: Check your `SEVEN_API_KEY` in `.secrets.php`
- **Insufficient credits**: Top up your Seven.io account
- **Invalid phone number**: Ensure phone number is in international format with `+`
- **Rate limit exceeded**: Wait before sending more messages

## Validation Provider Architecture

The system uses a pluggable validation provider architecture:

### Provider Interface

All providers implement `ValidationProviderInterface`:

```php
interface ValidationProviderInterface {
    public function getName(): string;
    public function sendCode(string $recipient, string $code, string $sessionId, array $config): bool;
    public function getUserId(string $recipient): ?int;
}
```

### Available Providers

1. **EmailValidationProvider** (always available)
   - Name: `email`
   - Lookup: Email address in Webling
   - Delivery: SMTP

2. **SmsValidationProvider** (requires Seven.io configuration)
   - Name: `sms`
   - Lookup: Phone number in Webling
   - Delivery: Seven.io SMS gateway

### Provider Selection

Providers are registered in `routes.php`:

```php
$validationProviderManager = new ValidationProviderManager();
$validationProviderManager->register(new EmailValidationProvider($weblingClient));
$validationProviderManager->register(new SmsValidationProvider($weblingClient, $sevenClient));
```

The provider is selected based on the `provider` parameter in the login request:
- If not specified or invalid: defaults to `email`
- If specified: uses the requested provider
- On failure: falls back to `email` (if email field is provided)

## Adding Custom Providers

You can add custom validation providers by:

1. Implementing `ValidationProviderInterface`
2. Registering the provider with `ValidationProviderManager`
3. Using the provider name in login requests

Example:

```php
class WhatsAppValidationProvider implements ValidationProviderInterface {
    public function getName(): string {
        return 'whatsapp';
    }
    
    public function sendCode(string $recipient, string $code, string $sessionId, array $config): bool {
        // Send code via WhatsApp
    }
    
    public function getUserId(string $recipient): ?int {
        // Find user by WhatsApp number
    }
}

// Register the provider
$validationProviderManager->register(new WhatsAppValidationProvider($weblingClient, $whatsappClient));
```

## Security Considerations

- Phone numbers are normalized before comparison to prevent bypass attempts
- User lookup uses exact matching after normalization
- Rate limiting applies to all authentication attempts regardless of provider
- Failed provider attempts are logged for monitoring
- Phone numbers are never exposed in API responses

## Testing

Test the SMS provider with PHPUnit:

```bash
vendor/bin/phpunit --filter ValidationProviderTest
vendor/bin/phpunit --filter SevenClientTest
```

Manual testing with curl:

```bash
# Test email validation
./tests/test-api.sh login user@example.com

# Test SMS validation (requires valid phone number in Webling)
curl -X POST http://localhost:8080/login \
  -H "X-API-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{"phone": "base64-encoded-phone", "provider": "sms"}'
```

## Troubleshooting

### SMS Provider Not Available

Check if the provider is registered:

```bash
# Check application logs for:
# "SMS validation provider not available: ..."
tail -f logs/app.log
```

Verify Seven.io configuration:

```bash
php -r "require 'config/.secrets.php'; echo getenv('SEVEN_API_KEY');"
```

### Phone Number Not Found

Ensure the phone number exists in Webling:
1. Log in to your Webling account
2. Find the user
3. Check the `Telefon 1` field
4. Verify the format matches (international with `+`)

### Base64 Encoding

Encode phone numbers correctly:

```bash
# Linux/Mac
echo -n "+41123456789" | base64

# PHP
php -r "echo base64_encode('+41123456789');"
```

Use URL-safe encoding for compatibility:

```php
$encoded = strtr(base64_encode($phone), '+/', '-_');
```
