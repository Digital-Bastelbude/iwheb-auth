# Configuration Guide

Complete guide for configuring the authentication system.

## Quick Start

```bash
cp config/secrets.php.example config/.secrets.php
php keygenerator.php                    # Generates encryption key and API key
nano config/.secrets.php                # Fill in values
chmod 600 config/.secrets.php
```

---

## 1. Secrets & Keys

### Encryption Key

Required for encrypting user tokens (32 bytes, base64-encoded):

```php
// In config/.secrets.php
putenv('ENCRYPTION_KEY=base64:' . base64_encode(random_bytes(32)));
```

**Generate:**
```bash
php keygenerator.php encryption
# or manually:
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

### API Keys

Configure in `config/.secrets.php`:

```php
$API_KEYS = [
    'your-generated-key' => [
        'name' => 'Main Application',
        'routes' => [
            'POST:/login',
            'POST:/validate/{session_id}',
            'GET:/session/check/{session_id}',
            'POST:/session/touch/{session_id}',
            'POST:/session/logout/{session_id}',
            'POST:/session/delegate/{session_id}',
            'GET:/user/{session_id}/info',
            'GET:/user/{session_id}/token'
        ],
        'scopes' => ['read', 'write'],
        'rate_limit' => [
            'window_seconds' => 60,
            'max_requests' => 100
        ]
    ]
];
```

**Generate API key:**
```bash
php keygenerator.php api
```

#### Access Control

**Routes:** Specific endpoints the key can access
```php
'routes' => ['POST:/login', 'GET:/user/{session_id}/info']
```

**Scopes:** Broader method-based control
- `read`: GET, HEAD, OPTIONS
- `write`: POST, PUT, DELETE

**Legacy Permissions:** (backwards compatibility)
- `user_info`: Access to user info endpoint
- `user_token`: Access to user token endpoint
- `delegate`: Access to session delegation

**Rate Limiting:** Per-key limits (optional)
```php
'rate_limit' => [
    'window_seconds' => 60,
    'max_requests' => 100
]
```

#### Examples

**Full access:**
```php
'admin-key' => ['name' => 'Admin', 'permissions' => ['user_info', 'user_token']]
```

**Read-only:**
```php
'frontend-key' => ['name' => 'Frontend', 'permissions' => ['user_info']]
```

**Login-only:**
```php
'login-key' => ['name' => 'Login Service', 'permissions' => []]
```

---

## 2. SMTP Email

Configure email sending for authentication codes.

### Environment Variables

Add to `config/.secrets.php`:

```php
putenv('SMTP_HOST=smtp.example.com');
putenv('SMTP_PORT=587');                        // 587=TLS, 465=SSL, 25=plain
putenv('SMTP_USERNAME=your-email@example.com');
putenv('SMTP_PASSWORD=your-password');
putenv('SMTP_FROM_EMAIL=noreply@example.com');
putenv('SMTP_FROM_NAME=Auth Service');          // Optional
putenv('SMTP_USE_TLS=true');                    // Recommended
```

### Common Providers

**Gmail:**
```php
putenv('SMTP_HOST=smtp.gmail.com');
putenv('SMTP_PORT=587');
putenv('SMTP_USE_TLS=true');
// Use App Password, not regular password
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

### Email Templates

Configure in `config/config.json`:

```json
{
  "email": {
    "login_code": {
      "subject": "Your Authentication Code: ###CODE###",
      "message": "Hello,\n\nYour code is: ###CODE###\n\nValid for 15 minutes.\n\n###LINK_BLOCK###\n\nBest regards",
      "link_block": "Auto-login link:\nhttps://app.example.com/validate?session=###SESSION_ID###&code=###CODE###"
    }
  }
}
```

**Placeholders:**
- `###CODE###` - 6-digit authentication code
- `###SESSION_ID###` - Session identifier
- `###LINK_BLOCK###` - Optional auto-login link

**Disable link block:**
```json
"link_block": ""
```

### Testing

```bash
curl -X POST http://localhost:8080/login \
  -H "X-API-Key: your-api-key" \
  -H "Content-Type: application/json" \
  -d '{"email":"base64-encoded-email"}'
```

---

## 3. Session Isolation

Sessions are bound to the API key that created them:

```
App A (key: abc) → Session S1 → Only App A can access S1
App B (key: xyz) → Session S2 → Only App B can access S2
```

---

## 4. Security Checklist

- ✅ Never commit `config/.secrets.php`
- ✅ `chmod 600 config/.secrets.php`
- ✅ Use strong keys (32+ characters)
- ✅ Enable TLS for SMTP (`SMTP_USE_TLS=true`)
- ✅ Use app passwords for Gmail/Office365
- ✅ Regular key rotation
- ✅ Configure SPF/DKIM for email domain

---

## 5. Troubleshooting

### Missing Environment Variables

**Error:** `Missing encryption key` or `SMTP configuration incomplete`

**Solution:** Check `config/.secrets.php` exists and is loaded in `public/index.php`

### Invalid Encryption Key Format

**Error:** `Invalid encryption key format`

**Solution:** Ensure key has `base64:` prefix:
```php
putenv('ENCRYPTION_KEY=base64:YourBase64EncodedKey==');
```

### API Key Not Working

**Solution:** 
- Verify exact key match in `$API_KEYS` (case-sensitive)
- Check permissions/routes array
- Test with `GET /session/check/{session_id}` (requires `user_info` permission)

### Email Not Sending

**Solutions:**
1. Verify SMTP credentials
2. Check firewall rules (port 587/465)
3. Review logs: `tail -f logs/api.log`
4. Test SMTP connection: `telnet smtp.example.com 587`

### Email in Spam Folder

**Solutions:**
1. Configure SPF records
2. Set up DKIM signing
3. Use reputable SMTP provider
4. Verify sender domain

---

## 6. File Permissions

```bash
chmod 600 config/.secrets.php   # Only owner can read/write
chmod 755 public/               # Web server can read
chmod 755 storage/              # Application can write
```

---

## 7. Environment Variables Reference

| Variable | Required | Example | Description |
|----------|----------|---------|-------------|
| `ENCRYPTION_KEY` | Yes | `base64:abc123...` | 32-byte key for token encryption |
| `SMTP_HOST` | For email | `smtp.gmail.com` | SMTP server hostname |
| `SMTP_PORT` | For email | `587` | SMTP port (587/465/25) |
| `SMTP_USERNAME` | For email | `user@example.com` | SMTP username |
| `SMTP_PASSWORD` | For email | `password` | SMTP password |
| `SMTP_FROM_EMAIL` | For email | `noreply@example.com` | Sender email |
| `SMTP_FROM_NAME` | No | `Auth Service` | Sender name |
| `SMTP_USE_TLS` | For email | `true` | Enable TLS encryption |
