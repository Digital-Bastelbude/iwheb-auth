# Secrets Configuration Setup

## Overview

The application now uses a `.secrets.php` file for secure management of sensitive configuration data. This file sets environment variables at runtime and is **not** committed to the Git repository.

The application also uses an API key-based access control system to manage permissions for different applications accessing the authentication API.

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

### 3. Generate API keys

Generate one or more API keys for your applications:

```bash
php -r "require 'keygenerator.php'; echo generateApiKey(32) . PHP_EOL;"
```

Run this command multiple times to generate different keys for different applications.

### 4. Fill in secrets file

Open `.secrets.php` and replace the placeholders:

```php
// Set the generated encryption key from step 2
putenv('ENCRYPTION_KEY=base64:YourGeneratedKeyHere...');

// Set your Webling domain (without .webling.ch)
putenv('WEBLING_DOMAIN=your-company');

// Set your Webling API key
putenv('WEBLING_API_KEY=your-api-key');

// Configure API keys with permissions
$API_KEYS = [
    'generated-api-key-1' => [
        'name' => 'Main Application',
        'permissions' => ['user_info', 'user_token']
    ],
    'generated-api-key-2' => [
        'name' => 'Limited App',
        'permissions' => []
    ],
];
```

### 5. Set file permissions (recommended)

```bash
chmod 600 .secrets.php
```

## Environment Variables

| Variable | Description | Format | Example |
|----------|-------------|--------|---------|
| `ENCRYPTION_KEY` | 32-byte key for UidEncryptor | `base64:...` | `base64:YourKey==` |
| `WEBLING_DOMAIN` | Webling subdomain | String | `mycompany` |
| `WEBLING_API_KEY` | Webling API key | String | `abc123...` |

## API Keys Configuration

### API Key Structure

Each API key is defined with:
- **Key**: A URL-safe alphanumeric string (32+ characters recommended)
- **name**: A descriptive name for the application
- **permissions**: An array of permission strings

### Available Permissions

| Permission | Description | Grants Access To |
|------------|-------------|------------------|
| `user_info` | Access user information | `POST /user/{session_id}/info` |
| `user_token` | Access encrypted user token | `POST /user/{session_id}/token` |

### Default Routes (Always Allowed)

All valid API keys have access to these routes by default:
- `POST /login` - Create a new session
- `POST /validate/{session_id}` - Validate a session code
- `GET /session/check/{session_id}` - Check if session is active
- `POST /session/touch/{session_id}` - Refresh session expiry
- `POST /session/logout/{session_id}` - Delete a session

### Session Isolation

**IMPORTANT**: Sessions are isolated by API key. A session can only be accessed by the same API key that created it. This means:
- API Key A creates a session → only API Key A can access/modify it
- API Key B cannot access sessions created by API Key A
- This applies to all session-related routes (validate, check, touch, logout, user/info, user/token)

### Example Configurations

#### Full Access Application
```php
'api-key-xyz' => [
    'name' => 'Main Web App',
    'permissions' => ['user_info', 'user_token']
]
```

#### Limited Application
```php
'api-key-abc' => [
    'name' => 'Mobile App',
    'permissions' => ['user_info'] // Can get user info but not token
]
```

#### Minimal Application
```php
'api-key-123' => [
    'name' => 'Third Party Integration',
    'permissions' => [] // Only login, validate, check, touch, logout
]
```

## Using API Keys

### Request Headers

Include your API key in requests using the `X-API-Key` header:

```bash
curl -X POST https://your-domain.com/login \
  -H "X-API-Key: your-api-key-here" \
  -H "Content-Type: application/json" \
  -d '{"email":"base64-encoded-email"}'
```

Alternative format using Authorization header:

```bash
curl -X POST https://your-domain.com/login \
  -H "Authorization: ApiKey your-api-key-here" \
  -H "Content-Type: application/json" \
  -d '{"email":"base64-encoded-email"}'
```

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
