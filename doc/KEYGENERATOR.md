# API Key Generator

Generate secure encryption keys and API keys with helpful configuration examples.

## Simple Usage

```bash
# Generate both encryption key and API key (recommended for setup)
php keygenerator.php

# Generate only encryption key
php keygenerator.php encryption

# Generate only API key
php keygenerator.php api

# Generate longer API key
php keygenerator.php api 64

# Show help
php keygenerator.php help
```

## Legacy/Advanced Usage

```bash
# Using as library (backward compatibility)
php -r "require 'keygenerator.php'; echo generateApiKey(32) . PHP_EOL;"

# Generate multiple keys
php -r "require 'keygenerator.php'; foreach (generateApiKeys(5, 32) as \$k) echo \$k . PHP_EOL;"
```

## Output Examples

**Complete Setup:**
```bash
$ php keygenerator.php
=== COMPLETE KEY SETUP ===

1. Encryption Key (for config/.secrets.php ENCRYPTION_KEY):
base64:YourRandomKeyHere...

Add to config/.secrets.php:
putenv('ENCRYPTION_KEY=base64:YourRandomKeyHere...');

2. API Key (for config/.secrets.php $API_KEYS array):
abc123def456...

Add to config/.secrets.php $API_KEYS array:
    'abc123def456...' => [
        'name' => 'Your App Name',
        'permissions' => ['user_info', 'user_token', 'delegate']
    ],

=== SETUP COMPLETE ===
```

## Functions

**`generateApiKey(int $length = 32): string`**  
Returns URL-safe key. Min: 16, recommended: 32+. Throws `InvalidArgumentException` if < 16.

**`generateApiKeys(int $count, int $length = 32): array`**  
Returns array of unique keys.

**`isValidApiKeyFormat(string $key): bool`**  
Validates format (alphanumeric + `-_`, length ≥ 16).

## Security

- `random_bytes()` cryptographic security
- URL-safe: `a-z A-Z 0-9 - _`
- Unique per generation

## Integration

Add generated keys to `config/.secrets.php` with full configuration:

```php
$API_KEYS = [
    'k3Jx9mP2QwR5tY8vN6bH7zL4cF1aD0sA' => [
        'name' => 'My Application',
        'permissions' => ['user_info', 'user_token', 'delegate'],
        'routes' => [
            'POST:/login',
            'POST:/validate/{session_id}',
            'GET:/session/check/{session_id}',
            'POST:/session/touch/{session_id}',
            'POST:/session/logout/{session_id}',
            'POST:/session/delegate/{session_id}',
            'POST:/user/{session_id}/info',
            'POST:/user/{session_id}/token'
        ],
        'scopes' => ['read', 'write'],
        'rate_limit' => [
            'window_seconds' => 60,
            'max_requests' => 100
        ]
    ]
];
```

**Note:** API keys are configured exclusively in `config/.secrets.php`. The `config.json` file no longer contains API key definitions.

See [SECRETS-SETUP.md](SECRETS-SETUP.md) for complete configuration guide.
