# Secrets Configuration Setup

## Overview

The application now uses a `.secrets.php` file for secure management of sensitive configuration data. This file sets environment variables at runtime and is **not** committed to the Git repository.

## Setup

### 1. Create secrets file

```bash
cp .secrets.php.example .secrets.php
```

### 2. Generate encryption key

Run the following command to generate a secure 32-byte key:

```bash
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

### 3. Fill in secrets file

Open `.secrets.php` and replace the placeholders:

```php
// Set the generated key from step 2
putenv('ENCRYPTION_KEY=base64:YourGeneratedKeyHere...');

// Set your Webling domain (without .webling.ch)
putenv('WEBLING_DOMAIN=your-company');

// Set your Webling API key
putenv('WEBLING_API_KEY=your-api-key');
```

### 4. Set file permissions (recommended)

```bash
chmod 600 .secrets.php
```

## Environment Variables

| Variable | Description | Format | Example |
|----------|-------------|--------|---------|
| `ENCRYPTION_KEY` | 32-byte key for UidEncryptor | `base64:...` | `base64:YourKey==` |
| `WEBLING_DOMAIN` | Webling subdomain | String | `mycompany` |
| `WEBLING_API_KEY` | Webling API key | String | `abc123...` |

## Security Notes

- ✅ `.secrets.php` is in `.gitignore` and will **not** be committed
- ✅ The file is loaded early in the bootstrap process (`public/index.php`)
- ✅ Missing or invalid secrets result in meaningful error messages
- ✅ `.secrets.php.example` can be safely committed (contains only placeholders)
- ⚠️ **Never** add real secrets to `.secrets.php.example`
- ⚠️ File permissions should be restrictive (`chmod 600`)

## Code Changes

### 1. `public/index.php`
Loads `.secrets.php` before all other modules:

```php
// Load environment variables from .secrets.php before anything else
$secretsFile = BASE_DIR . '/.secrets.php';
if (!file_exists($secretsFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration error: .secrets.php file not found']);
    exit;
}
require_once $secretsFile;
```

### 2. `routes.php`
Uses environment variables with validation:

```php
// Initialize Webling client from environment variables
$weblingDomain = getenv('WEBLING_DOMAIN');
$weblingApiKey = getenv('WEBLING_API_KEY');

if (!$weblingDomain || !$weblingApiKey) {
    throw new \RuntimeException('WEBLING_DOMAIN and WEBLING_API_KEY must be set');
}

// Load encryption key from environment
$encryptionKey = getenv('ENCRYPTION_KEY');
if (!$encryptionKey || strpos($encryptionKey, 'base64:') !== 0) {
    throw new \RuntimeException('ENCRYPTION_KEY must be set with base64: prefix');
}

$uidEncryptor = new UidEncryptor(UidEncryptor::loadKeyFromEnv('ENCRYPTION_KEY'), 'iwheb-auth');
```

## Deployment

For production environments:

1. Ensure `.secrets.php` exists on the server
2. Fill in production credentials
3. Set restrictive file permissions: `chmod 600 .secrets.php`
4. Ensure the web server can read the file
5. Verify that `.secrets.php` is not publicly accessible

## Error Handling

| Error | Cause | Solution |
|-------|-------|----------|
| "Configuration error: .secrets.php file not found" | `.secrets.php` does not exist | Create file from `.secrets.php.example` |
| "WEBLING_DOMAIN and WEBLING_API_KEY must be set" | Environment variables missing | Check `.secrets.php` |
| "ENCRYPTION_KEY must be set with base64: prefix" | Invalid encryption key | Generate new key with correct format |
| "Invalid base64 key in env var" | Key has wrong length | Generate new 32-byte key |
