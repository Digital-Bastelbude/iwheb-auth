# iWheb Authentication Service - Documentation

Secure PHP-based authentication with Webling integration, session management, and API-key-based access control.

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [API Overview](#api-overview)
- [Project Structure](#project-structure)
- [Security Features](#security-features)
- [Testing](#testing)
- [Development](#development)
- [Production Deployment](#production-deployment)

## Features

- **Secure Authentication** via Webling API
- **Session Management** with 6-digit verification codes (30min validity)
- **API Key Authorization** with granular permissions (`user_info`, `user_token`)
- **Session Isolation** - sessions are bound to the creating API key
- **UID Encryption** using XChaCha20-Poly1305 AEAD
- **Comprehensive Tests** - 153 tests with 554 assertions

## Installation

### Prerequisites

- PHP 8.1+ with extensions: `libsodium`, `sqlite3`, `json`, `curl`
- Composer
- SQLite3
- Webling account with API key

### Setup

```bash
# Clone repository
git clone <repository-url>
cd iwheb-auth

# Install dependencies
composer install

# Configure secrets
cp config/secrets.php.example config/.secrets.php
chmod 600 config/.secrets.php

# Edit secrets (see SECRETS-SETUP.md)
nano config/.secrets.php
```

### Generate Secrets

```bash
# Generate encryption key (32 bytes)
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"

# Generate API key (32 characters)
php -r "require 'keygenerator.php'; echo generateApiKey(32) . PHP_EOL;"
```

**Note:** For detailed secrets configuration see [SECRETS-SETUP.md](SECRETS-SETUP.md)

### Start Development Server

```bash
php -S localhost:8080 -t public
```

### Run Tests

```bash
# All tests
vendor/bin/phpunit --colors=always --testdox

# With strict error handling
TEST_STRICT_ERRORS=1 vendor/bin/phpunit

# Specific test class
vendor/bin/phpunit tests/SessionTest.php
```

## API Overview

### Authentication

All requests require a valid API key in the header:

```bash
X-API-Key: your-api-key-here
# OR
Authorization: ApiKey your-api-key-here
```

### Login Flow

1. **Request login** - `POST /login` with Webling credentials
2. **Validate code** - `POST /validate/{sessionId}` with 6-digit code
3. **Use session** - Use session ID for authenticated requests

Detailed flow: [LOGIN-FLOW.md](LOGIN-FLOW.md)

### Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| `POST` | `/login` | - | Initiate login session |
| `POST` | `/validate/{id}` | - | Validate session code |
| `GET` | `/session/check/{id}` | - | Check session status |
| `POST` | `/session/touch/{id}` | - | Refresh session |
| `POST` | `/session/logout/{id}` | - | End session |
| `GET` | `/user/{id}/info` | `user_info` | Get user information |
| `GET` | `/user/{id}/token` | `user_token` | Get user token |

**Default routes** (login, validate, session management) are accessible to all valid API keys.  
**Permission-based routes** require specific permissions in `config/.secrets.php`.

## Project Structure

```
iwheb-auth/
├── config/
│   ├── .secrets.php          # ⚠️ Secret credentials (NOT in Git!)
│   ├── secrets.php.example   # Template for secrets
│   └── config.json           # Rate limit configuration
├── doc/
│   ├── README.md             # This file
│   ├── SECRETS-SETUP.md      # Secrets configuration
│   ├── LOGIN-FLOW.md         # Authentication flow
│   └── KEYGENERATOR.md       # API key generation
├── public/
│   └── index.php             # Entry point
├── storage/                  # SQLite database
├── tests/                    # PHPUnit tests
├── vendor/                   # Composer dependencies
└── *.php                     # Core modules
```

### Core Modules

| File | Purpose |
|------|---------|
| `routes.php` | Route definitions and handlers |
| `storage.php` | SQLite operations (users, sessions) |
| `weblingclient.php` | Webling API integration |
| `uidencryptor.php` | UID encryption/decryption |
| `apikeymanager.php` | API key validation & permissions |
| `keygenerator.php` | Secure API key generation |
| `access.php` | Authorization logic |
| `response.php` | Response helpers |
| `logging.php` | Logging |
| `exceptions.php` | Custom exceptions |

## Security Features

### API Key Permissions

API keys can have granular permissions:

```php
$API_KEYS = [
    'frontend-app-key' => [
        'name' => 'Frontend App',
        'permissions' => ['user_info']  // Only /user/{id}/info
    ],
    'admin-service-key' => [
        'name' => 'Admin Service',
        'permissions' => ['user_info', 'user_token']  // Full access
    ]
];
```

**Available Permissions:**
- `user_info` - Access to `/user/{id}/info`
- `user_token` - Access to `/user/{id}/token`

### Session Isolation

Sessions are bound to the API key that created them:

```
App A (key: app-a-123) creates session
  → Only App A can access this session

App B (key: app-b-456) creates session
  → Only App B can access this session
```

**Prevents:**
- Cross-application session access
- Session hijacking between apps
- Privilege escalation between API keys

### Encryption

- User IDs are encrypted with **XChaCha20-Poly1305 AEAD**
- 256-bit encryption keys
- Authenticated encryption prevents tampering

## Testing

### Run Test Suite

```bash
# All tests
vendor/bin/phpunit

# With detailed output
vendor/bin/phpunit --colors=always --testdox

# Strict mode (notices/deprecations as errors)
TEST_STRICT_ERRORS=1 vendor/bin/phpunit

# Coverage report (requires Xdebug)
vendor/bin/phpunit --coverage-html coverage/
```

### Test Coverage

- **153 tests** with **554 assertions**
- Test classes:
  - `SessionTest` (43 tests) - Session management & API key isolation
  - `ApiKeyManagerTest` (23 tests) - API key validation & permissions
  - `StorageTest` (10 tests) - Database operations
  - `UidEncryptorTest` (29 tests) - Encryption
  - `KeyGeneratorTest` (11 tests) - API key generation
  - `AccessTest` (10 tests) - Authorization
  - `RoutesLogicTest` (20 tests) - Routing
  - `DatabaseTest` (7 tests) - Database basics

## Development

### Add New Routes

In `routes.php`:

```php
if (preg_match('#^/myroute/(\d+)$#', $PATH, $m)) {
    $id = (int)$m[1];
    
    // Optional: Check permission
    if (!$apiKeyManager->hasPermission($apiKey, 'my_permission')) {
        Response::getInstance()->notFound($apiKey, 'FORBIDDEN');
    }
    
    // Handle request
    $response->sendJson(['data' => $id], 200);
}
```

### Add API Key Permission

**1. In `config/.secrets.php`:**

```php
'your-key' => [
    'name' => 'App Name',
    'permissions' => ['user_info', 'your_new_permission']
]
```

**2. Check in route:**

```php
if (!$apiKeyManager->hasPermission($apiKey, 'your_new_permission')) {
    Response::getInstance()->notFound($apiKey, 'FORBIDDEN');
}
```

## Production Deployment

### Checklist

- [ ] **Never** commit `config/.secrets.php`
- [ ] Restrictive permissions: `chmod 600 config/.secrets.php`
- [ ] Strong, unique API keys (32+ characters)
- [ ] Enable HTTPS/TLS
- [ ] Configure PHP error handling
- [ ] Use production web server (Apache/Nginx)
- [ ] Regular key rotation
- [ ] Setup logging and monitoring
- [ ] Backup strategy for SQLite database

### Web Server Configuration

**Apache (.htaccess):**
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]

<Files ".secrets.php">
    Require all denied
</Files>
```

**Nginx:**
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ /config/\.secrets\.php$ {
    deny all;
}
```

## Troubleshooting

### Tests Fail

```bash
# Syntax check all PHP files
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 php -l

# Reset database
rm -rf storage/*.db
vendor/bin/phpunit
```

### API Key Not Working

1. Check if key is defined in `config/.secrets.php`
2. Verify header format: `X-API-Key: your-key` or `Authorization: ApiKey your-key`
3. Check permissions for route

### Session Access Denied

- Verify the same API key is used that created the session
- Session isolation is intentional - each API key has isolated sessions

## Further Documentation

- **[SECRETS-SETUP.md](SECRETS-SETUP.md)** - Detailed secrets configuration
- **[LOGIN-FLOW.md](LOGIN-FLOW.md)** - Login flow with examples
- **[KEYGENERATOR.md](KEYGENERATOR.md)** - API key generation

## License

See `LICENCE` file.
