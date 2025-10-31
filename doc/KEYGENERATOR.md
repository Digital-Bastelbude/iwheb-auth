# API Key Generator

Generate secure, URL-safe API keys.

## Usage

```bash
# Single key (32 chars)
php -r "require 'keygenerator.php'; echo generateApiKey(32) . PHP_EOL;"

# Custom length
php -r "require 'keygenerator.php'; echo generateApiKey(64) . PHP_EOL;"

# Multiple keys
php -r "require 'keygenerator.php'; foreach (generateApiKeys(5, 32) as \$k) echo \$k . PHP_EOL;"
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

Add to `config/.secrets.php`:

```php
$API_KEYS = [
    'k3Jx9mP2QwR5tY8vN6bH7zL4cF1aD0sA' => [
        'name' => 'My App',
        'permissions' => ['user_info', 'user_token']
    ]
];
```

See [SECRETS-SETUP.md](SECRETS-SETUP.md).
