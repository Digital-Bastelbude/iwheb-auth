# iWheb Authentication Service

Secure PHP authentication with Webling integration, session management, and API key authorization.

## Features

- 🔐 Webling API authentication
- �� 6-digit code sessions (30min)
- 🔑 API key permissions
- 🛡️ Session isolation per key
- 🔒 XChaCha20-Poly1305 encryption
- ✅ 153 tests

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
│   ├── .secrets.php       # Credentials (NOT in Git)
│   └── secrets.php.example
├── public/index.php       # Entry point
├── storage/               # SQLite DB
├── tests/                 # PHPUnit
└── *.php                  # Core modules
```

**Modules:** `routes.php`, `storage.php`, `weblingclient.php`, `uidencryptor.php`, `apikeymanager.php`, `keygenerator.php`

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

**Add Route:**
```php
if (preg_match('#^/myroute/(\d+)$#', $PATH, $m)) {
    if (!$apiKeyManager->hasPermission($apiKey, 'my_perm')) {
        Response::getInstance()->notFound($apiKey, 'FORBIDDEN');
    }
    $response->sendJson(['data' => (int)$m[1]], 200);
}
```

**Add Permission:** Add to `config/.secrets.php` permissions array, check in route.

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
- [KEYGENERATOR.md](KEYGENERATOR.md) - Key generation

**Requirements:** PHP 8.1+ with libsodium, sqlite3, json, curl | Composer | Webling account
