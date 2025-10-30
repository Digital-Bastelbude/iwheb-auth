# API Key Generator

This utility generates secure, URL-safe alphanumeric API keys for the authentication system.

## Usage

### Generate a single API key (32 characters)

```bash
php -r "require 'keygenerator.php'; echo generateApiKey(32) . PHP_EOL;"
```

Example output:
```
k3Jx9mP2QwR5tY8vN6bH7zL4cF1aD0sA
```

### Generate a single API key (custom length)

```bash
php -r "require 'keygenerator.php'; echo generateApiKey(64) . PHP_EOL;"
```

### Generate multiple API keys

```bash
php -r "require 'keygenerator.php'; foreach (generateApiKeys(5, 32) as \$key) echo \$key . PHP_EOL;"
```

## Functions

### `generateApiKey(int $length = 32): string`

Generates a single secure, URL-safe alphanumeric API key.

- **Parameters:**
  - `$length`: The length of the key (minimum: 16, recommended: 32 or more)
- **Returns:** A URL-safe alphanumeric string
- **Throws:** `InvalidArgumentException` if length < 16

### `generateApiKeys(int $count, int $length = 32): array`

Generates multiple unique API keys.

- **Parameters:**
  - `$count`: Number of keys to generate
  - `$length`: Length of each key
- **Returns:** Array of unique API keys

### `isValidApiKeyFormat(string $key): bool`

Validates that a key contains only URL-safe characters (alphanumeric, -, _).

- **Parameters:**
  - `$key`: The key to validate
- **Returns:** `true` if valid, `false` otherwise

## Character Set

Generated keys use URL-safe characters:
- Lowercase letters: `a-z`
- Uppercase letters: `A-Z`
- Numbers: `0-9`
- Special characters: `-`, `_`

**No padding or special characters that need URL encoding!**

## Security Notes

- Keys are generated using `random_bytes()` which provides cryptographically secure randomness
- Minimum recommended length is 32 characters
- Keys should be stored securely in `.secrets.php`
- Never commit actual API keys to version control
- Use `.secrets.php.example` with placeholders for sharing configuration templates

## Integration

API keys generated with this tool can be added to `.secrets.php`:

```php
$API_KEYS = [
    'k3Jx9mP2QwR5tY8vN6bH7zL4cF1aD0sA' => [
        'name' => 'Main Application',
        'permissions' => ['user_info', 'user_token']
    ],
];
```

See `SECRETS-SETUP.md` for complete configuration instructions.
