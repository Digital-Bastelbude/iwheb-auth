# iWheb Authentication Service

Secure PHP authentication with Webling integration, session management, and API key authorization.

## Features

- 🔐 Webling API authentication
- 📧 Email code delivery via SMTP
- 🔢 6-digit code sessions (30min)
- 🔑 API key permissions
- 🛡️ Session isolation per key
- 🔒 XChaCha20-Poly1305 encryption
- ✅ 162 tests, 578 assertions

## Quick Setup

```bash
composer install
cp config/secrets.php.example config/.secrets.php
chmod 600 config/.secrets.php

# Generate keys
php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
php -r "require 'keygenerator.php'; echo generateApiKey(32) . PHP_EOL;"

# Edit config/.secrets.php, then:
php -S localhost:8080 -t public
vendor/bin/phpunit --testdox
```

See [SECRETS-SETUP.md](SECRETS-SETUP.md) for configuration details.

## API Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| POST | `/login` | - | Initiate login |
| POST | `/validate/{id}` | - | Validate code |
| GET | `/session/check/{id}` | - | Check status |
| POST | `/session/touch/{id}` | - | Refresh |
| POST | `/session/logout/{id}` | - | Logout |
| GET | `/user/{id}/info` | `user_info` | User info |
| GET | `/user/{id}/token` | `user_token` | User token |

**Auth:** `X-API-Key: your-key` or `Authorization: ApiKey your-key`

See [LOGIN-FLOW.md](LOGIN-FLOW.md) for details.

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

- [SECRETS-SETUP.md](SECRETS-SETUP.md) - Configuration
- [LOGIN-FLOW.md](LOGIN-FLOW.md) - Auth flow
- [EMAIL-CONFIGURATION.md](EMAIL-CONFIGURATION.md) - SMTP email setup
- [DEPLOYMENT.md](DEPLOYMENT.md) - Production deployment
- [KEYGENERATOR.md](KEYGENERATOR.md) - Key generation

**Requirements:** PHP 8.1+ with libsodium, sqlite3, json, curl | Composer | Webling account
