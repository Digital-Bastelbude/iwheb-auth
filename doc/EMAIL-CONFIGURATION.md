# SMTP Email Configuration

This document describes how to configure email sending for authentication codes.

## Overview

The authentication system can send login codes via email using SMTP. When a user initiates login via `POST /login`, the system:

1. Generates a 6-digit authentication code
2. Creates a session
3. Sends the code via email to the user
4. Returns the session ID and code in the API response (fallback)

## Configuration

### 1. SMTP Server Settings (Environment Variables)

Add the following to your `config/.secrets.php`:

```php
// SMTP Server Configuration
putenv('SMTP_HOST=smtp.example.com');           // SMTP server hostname
putenv('SMTP_PORT=587');                        // Port (587=TLS, 465=SSL, 25=plain)
putenv('SMTP_USERNAME=your-email@example.com'); // SMTP username
putenv('SMTP_PASSWORD=your-password');          // SMTP password
putenv('SMTP_FROM_EMAIL=noreply@example.com');  // Sender email
putenv('SMTP_FROM_NAME=Auth Service');          // Sender name (optional)
putenv('SMTP_USE_TLS=true');                    // Use TLS encryption (true/false)
```

#### Common SMTP Providers

**Gmail:**
```php
putenv('SMTP_HOST=smtp.gmail.com');
putenv('SMTP_PORT=587');
putenv('SMTP_USE_TLS=true');
// Note: Use App Password, not your regular Gmail password
```

**Office 365:**
```php
putenv('SMTP_HOST=smtp.office365.com');
putenv('SMTP_PORT=587');
putenv('SMTP_USE_TLS=true');
```

**SendGrid:**
```php
putenv('SMTP_HOST=smtp.sendgrid.net');
putenv('SMTP_PORT=587');
putenv('SMTP_USERNAME=apikey');
putenv('SMTP_PASSWORD=your-sendgrid-api-key');
putenv('SMTP_USE_TLS=true');
```

### 2. Email Templates (config.json)

Configure the email content in `config/config.json`:

```json
{
  "email": {
    "login_code": {
      "subject": "Your Authentication Code: ###CODE###",
      "message": "Hello,\n\nYour authentication code is: ###CODE###\n\nThis code is valid for 15 minutes.\n\n###LINK_BLOCK###\n\nIf you did not request this code, please ignore this email.\n\nBest regards,\nAuthentication Service",
      "link_block": "Alternatively, you can click this link to authenticate automatically:\nhttps://your-app.example.com/validate?session=###SESSION_ID###&code=###CODE###"
    }
  }
}
```

## Template Placeholders

### Available Placeholders

- `###CODE###` - The 6-digit authentication code
- `###SESSION_ID###` - The session ID
- `###LINK_BLOCK###` - Placeholder for the optional link block

### Subject Template

The `subject` field supports:
- `###CODE###` - Will be replaced with the actual code

Example:
```json
"subject": "Your Authentication Code: ###CODE###"
```

### Message Template

The `message` field is the main email body and supports:
- `###CODE###` - The authentication code
- `###SESSION_ID###` - The session ID
- `###LINK_BLOCK###` - Placeholder where the link block will be inserted

Example:
```json
"message": "Hello,\n\nYour code is: ###CODE###\n\n###LINK_BLOCK###\n\nRegards"
```

### Link Block (Optional)

The `link_block` is optional and provides a clickable link for automatic authentication:

- If `link_block` is provided and not empty, it will replace `###LINK_BLOCK###` in the message
- If `link_block` is empty or not provided, `###LINK_BLOCK###` will be removed from the message
- The link block supports `###CODE###` and `###SESSION_ID###` placeholders

Example with link block:
```json
"link_block": "Click here to authenticate:\nhttps://app.example.com/validate?session=###SESSION_ID###&code=###CODE###"
```

Example without link block (disable feature):
```json
"link_block": ""
```

Or simply omit the `link_block` field entirely.

## Email Template Examples

### Example 1: Simple Code Only

```json
{
  "email": {
    "login_code": {
      "subject": "Your Login Code",
      "message": "Your authentication code is: ###CODE###\n\nThis code expires in 15 minutes."
    }
  }
}
```

Result: Email contains only the code, no link.

### Example 2: Code with Auto-Login Link

```json
{
  "email": {
    "login_code": {
      "subject": "Login to Your Account - Code: ###CODE###",
      "message": "Hello,\n\nYour authentication code is: ###CODE###\n\n###LINK_BLOCK###\n\nThe code expires in 15 minutes.\n\nBest regards",
      "link_block": "For quick access, click this link:\nhttps://myapp.com/auth?s=###SESSION_ID###&c=###CODE###"
    }
  }
}
```

Result: Email contains the code and a clickable auto-login link.

### Example 3: Multi-language Support

```json
{
  "email": {
    "login_code": {
      "subject": "Ihr Authentifizierungscode: ###CODE###",
      "message": "Hallo,\n\nIhr Code lautet: ###CODE###\n\nGültig für 15 Minuten.\n\n###LINK_BLOCK###\n\nMit freundlichen Grüßen",
      "link_block": "Alternativ können Sie hier klicken:\nhttps://app.example.de/validate?session=###SESSION_ID###&code=###CODE###"
    }
  }
}
```

## Error Handling

**IMPORTANT:** Email delivery is **required** for authentication:

- If email sending fails, the API request will fail with an error
- The authentication code is **NOT** included in the API response
- Users can **only** authenticate via the email code (no fallback)
- This ensures codes are only delivered through secure email channels

**API Error Response on Email Failure:**
```json
{
  "error": "Failed to send email: SMTP Error: ...",
  "status": 500
}
```

Check error logs for SMTP issues:
```bash
tail -f logs/api.log
# or PHP error log
tail -f /var/log/php/error.log
```

**Important:** Ensure SMTP configuration is working before deploying to production!

## Testing

### Test SMTP Configuration

Create a simple test script:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/.secrets.php';

use IwhebAPI\UserAuth\Http\SmtpMailer;

try {
    $mailer = SmtpMailer::fromEnv();
    $success = $mailer->send(
        'test@example.com',
        'Test Email',
        'This is a test message from the authentication system.'
    );
    
    echo $success ? "Email sent successfully!\n" : "Failed to send email.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

### Test Email Template

Test the complete authentication flow:

```bash
curl -X POST http://localhost:8080/login \
  -H "X-API-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{"email":"base64-encoded-email"}'
```

Check that:
1. Email is received
2. Code in email matches API response
3. Link (if configured) contains correct session ID and code
4. Placeholders are replaced correctly

## Security Considerations

1. **Use TLS/SSL**: Always enable `SMTP_USE_TLS=true` for production
2. **App Passwords**: For Gmail/Office365, use app-specific passwords
3. **Secure Credentials**: Never commit `config/.secrets.php` to version control
4. **Rate Limiting**: Email sending respects API rate limits
5. **Link Expiration**: Auto-login links expire with the code (15 minutes)

## Troubleshooting

### Email Not Sending

1. Check SMTP credentials in `config/.secrets.php`
2. Verify SMTP server allows connections from your IP
3. Check firewall rules (port 587/465)
4. Review error logs
5. Test with a simple SMTP client (telnet/openssl)

### Email in Spam

1. Configure SPF records for your domain
2. Set up DKIM signing
3. Use a reputable SMTP service
4. Verify sender email domain

### Placeholders Not Replaced

1. Ensure placeholders use exact format: `###CODE###`
2. Check JSON escaping in config.json
3. Verify no extra spaces around placeholders

## API Response

**Success Response (email sent):**

```json
{
  "data": {
    "session_id": "abc123def456",
    "code_expires_at": "2025-10-31T12:45:00Z",
    "session_expires_at": "2025-10-31T13:00:00Z"
  },
  "status": 200
}
```

**Note:** The authentication code is **NOT** included in the response. Users must retrieve it from their email.

**Error Response (email failed):**

```json
{
  "error": "Failed to send email: SMTP Error: Connection refused",
  "status": 500
}
```

This ensures that authentication codes are only delivered through the secure email channel.
