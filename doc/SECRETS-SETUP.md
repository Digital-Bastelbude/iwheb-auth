# Secrets Configuration

## Quick Setup

```bash
cp config/secrets.php.example config/.secrets.php
php keygenerator.php                    # Generates both encryption key and API key
nano config/.secrets.php                # Fill in your values
chmod 600 config/.secrets.php
```

## Manual Setup

```bash
cp config/secrets.php.example config/.secrets.php
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"  # Encryption key
php keygenerator.php encryption          # Encryption key only
php keygenerator.php api                 # API key only
nano config/.secrets.php
chmod 600 config/.secrets.php
```

## API Key Configuration

API keys are configured exclusively in `config/.secrets.php` with full access control:

```php
$API_KEYS = [
    'your-generated-key' => [
        'name' => 'Main Application',           // Descriptive name
        'permissions' => ['user_info', 'user_token', 'delegate'],  // Legacy permissions
        'routes' => [                           // Specific allowed routes
            'POST:/login',
            'POST:/validate/{session_id}',
            'GET:/session/check/{session_id}',
            'POST:/session/touch/{session_id}',
            'POST:/session/logout/{session_id}',
            'POST:/session/delegate/{session_id}',
            'POST:/user/{session_id}/info',
            'POST:/user/{session_id}/token'
        ],
        'scopes' => ['read', 'write'],          // Access scopes
        'rate_limit' => [                       // Custom rate limiting
            'window_seconds' => 60,
            'max_requests' => 100
        ]
    ]
];
```

## Access Control Options

### Routes
Specific endpoints the API key can access:
```php
'routes' => [
    'POST:/login',                          // Authentication
    'POST:/validate/{session_id}',          // Code validation
    'GET:/session/check/{session_id}',      // Session status
    'POST:/session/touch/{session_id}',     // Session renewal
    'POST:/session/logout/{session_id}',    // Session termination
    'POST:/session/delegate/{session_id}',  // Session delegation
    'POST:/user/{session_id}/info',         // User information
    'POST:/user/{session_id}/token'         // User token generation
]
```

### Scopes
Broader access control:
- `read`: Safe operations (GET, HEAD, OPTIONS)
- `write`: Modifying operations (POST, PUT, DELETE)

### Legacy Permissions
Backward compatibility with simple permission system:
- `user_info`: Access to `/user/{session_id}/info`
- `user_token`: Access to `/user/{session_id}/token` 
- `delegate`: Access to `/session/delegate/{session_id}`

### Rate Limiting
Per-key rate limiting:
```php
'rate_limit' => [
    'window_seconds' => 60,     // Time window
    'max_requests' => 100       // Max requests per window
]
// Omit for default rate limits from config.json
```

## Session Isolation

Sessions bound to creating API key. Only that key can access them.

```
App A (key: abc) → Session S1 → Only App A can access S1
App B (key: xyz) → Session S2 → Only App B can access S2
```

## Examples

**Full access:**
```php
'full-key' => ['name' => 'Admin', 'permissions' => ['user_info', 'user_token']]
```

**Read-only:**
```php
'readonly-key' => ['name' => 'Frontend', 'permissions' => ['user_info']]
```

**Login-only:**
```php
'login-key' => ['name' => 'Login Service', 'permissions' => []]
```

## Security

- ✅ Never commit `config/.secrets.php`
- ✅ `chmod 600 config/.secrets.php`
- ✅ Strong keys (32+ chars)
- ✅ Regular rotation

## Troubleshooting

- **Missing vars:** Check file exists, loaded in `public/index.php`
- **Invalid format:** `ENCRYPTION_KEY` needs `base64:` prefix
- **Key not working:** Verify exact match in `$API_KEYS` (case-sensitive)
- **Permission denied:** Check permissions array
