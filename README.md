# iWheb Authentication Service

Secure PHP-based authentication with Webling integration, session management, and API-key authorization.

## Features

- 🔐 Secure user authentication via Webling API
- 🎫 Session management with 6-digit verification codes
- 🔑 API key authorization with granular permissions
- 🛡️ Session isolation per API key
- 🔒 UID encryption (XChaCha20-Poly1305)
- ✅ Comprehensive tests (153 tests, 554 assertions)

## Quick Start

```bash
# Install dependencies
composer install

# Configure secrets
cp config/secrets.php.example config/.secrets.php
chmod 600 config/.secrets.php
# Edit secrets (see: doc/SECRETS-SETUP.md)

# Start development server
php -S localhost:8080 -t public

# Run tests
vendor/bin/phpunit --colors=always --testdox
```

## Documentation

Complete documentation is located in the `doc/` directory:

- **[📖 Main Documentation](doc/README.md)** - Complete overview, features and usage
- **[🔐 Secrets Setup](doc/SECRETS-SETUP.md)** - Configure API keys and credentials
- **[🔑 Login Flow](doc/LOGIN-FLOW.md)** - Authentication process details
- **[🎫 Key Generator](doc/KEYGENERATOR.md)** - Generate and manage API keys

## Requirements

- PHP 8.1+ with `libsodium`, `sqlite3`, `json`, `curl`
- Composer
- Webling account with API access

## License

See `LICENCE` file.
