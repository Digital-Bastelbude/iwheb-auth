# IWHEB Authentication Service

Secure PHP authentication with Webling integration, session management, and API key authorization.

## Features

- 🔐 Webling API authentication
- 📧 Email code delivery via SMTP
- 🔢 6-digit code sessions (30min)
- 🔑 API key permissions
- 🛡️ Session isolation per key
- � Delegated cross-app sessions
- �🔒 XChaCha20-Poly1305 encryption
- ✅ 170 tests, 615 assertions

## Quick Setup

```bash
composer install
cp config/secrets.php.example config/.secrets.php
chmod 600 config/.secrets.php

# Generate keys (creates both encryption key and API key)
php keygenerator.php

# Edit config/.secrets.php with your settings, then:
php -S localhost:8080 -t public
vendor/bin/phpunit --testdox
```

See [CONFIG.md](CONFIG.md) for complete configuration details.

## API Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| POST | `/login` | - | Initiate login |
| POST | `/validate/{id}` | - | Validate code |
| GET | `/session/check/{id}` | - | Check status |
| POST | `/session/touch/{id}` | - | Refresh |
| POST | `/session/delegate/{id}` | `delegate_session` | Delegate session |
| POST | `/session/logout/{id}` | - | Logout |
| GET | `/user/{id}/info` | `user_info` | User info |
| GET | `/user/{id}/token` | `user_token` | User token |

**Auth:** `X-API-Key: your-key` or `Authorization: ApiKey your-key`

**Quick Reference:**
- � [LOGIN-FLOW.md](LOGIN-FLOW.md) - authentication flow details
- 📘 [openapi.yaml](openapi.yaml) - OpenAPI specification

## Key Generation

Generate secure keys using the included generator:

```bash
# Complete setup (encryption key + API key)
php keygenerator.php

# Only encryption key
php keygenerator.php encryption

# Only API key (custom length)
php keygenerator.php api 64
```

**Output example:**
```
=== COMPLETE KEY SETUP ===

1. Encryption Key (for config/.secrets.php):
base64:YourRandomKeyHere...

Add: putenv('ENCRYPTION_KEY=base64:YourRandomKeyHere...');

2. API Key (for config/.secrets.php $API_KEYS):
abc123def456...

Add to $API_KEYS array:
    'abc123def456...' => [
        'name' => 'Your App',
        'permissions' => ['user_info', 'user_token', 'delegate']
    ],
```

**Security:** Uses `random_bytes()`, URL-safe format, minimum 16 characters.

## Structure

```
├── config/
│   ├── .secrets.php          # Credentials (NOT in Git)
│   ├── config.json           # Email templates, rate limits
│   └── config-userauth.php   # Config loader
├── public/index.php          # Entry point
├── src/UserAuth/
│   ├── Auth/                 # Authorization logic
│   ├── Database/             # Storage + Repositories
│   ├── Http/                 # Routes, Controllers, SMTP
│   └── Exception/            # Custom exceptions
├── storage/                  # SQLite DB
└── tests/                    # PHPUnit tests
```

**Architecture:** MVC with Repository pattern, PSR-4 autoloading.

## Security

**Permissions:**
```php
$API_KEYS = [
    'key1' => ['name' => 'App', 'permissions' => ['user_info']],
    'key2' => ['name' => 'Admin', 'permissions' => ['user_info', 'user_token']]
];
```

**Session Isolation:** Sessions bound to creating API key.

**Encryption:** 256-bit XChaCha20-Poly1305 AEAD.

## Development

**Add Controller Method:**
```php
// In src/UserAuth/Http/Controllers/MyController.php
public function myAction(array $pathVars, array $body): array {
    $this->requirePermission('my_perm');
    return $this->success(['data' => $pathVars['id']]);
}
```

**Add Route:** Edit `src/UserAuth/Http/routes.php`
```php
$routes[] = [
    'pattern' => '#^/myroute/([a-z0-9]+)$#',
    'pathVars' => ['id'],
    'methods' => ['POST' => [$myController, 'myAction']]
];
```

**Add Permission:** Add to `config/.secrets.php` permissions array.

## Testing

```bash
vendor/bin/phpunit                          # All
TEST_STRICT_ERRORS=1 vendor/bin/phpunit     # Strict
vendor/bin/phpunit tests/SessionTest.php    # Specific
```

## Production

- [ ] Never commit `config/.secrets.php`
- [ ] `chmod 600 config/.secrets.php`
- [ ] Strong keys (32+ chars)
- [ ] HTTPS/TLS
- [ ] Production server (Apache/Nginx)
- [ ] Key rotation
- [ ] Monitoring & backups

**Apache:** Deny `.secrets.php`, rewrite to `index.php`  
**Nginx:** Deny `config/.secrets.php`, try_files to `index.php`

## Troubleshooting

**Tests fail:** `find . -name '*.php' -not -path './vendor/*' | xargs php -l && rm storage/*.db && vendor/bin/phpunit`

**API key issues:** Check `config/.secrets.php`, verify header format, check permissions

**Session denied:** Verify same API key (isolation is intentional)

## Docs

- [CONFIG.md](CONFIG.md) - Complete configuration guide
- [LOGIN-FLOW.md](LOGIN-FLOW.md) - Authentication flow
- [DEPLOYMENT.md](DEPLOYMENT.md) - Production deployment
- [DELEGATED-SESSIONS.md](DELEGATED-SESSIONS.md) - Cross-app sessions
- [openapi.yaml](openapi.yaml) - OpenAPI 3.0 specification

**Requirements:** PHP 8.1+ with libsodium, sqlite3, json, curl | Composer | Webling account
