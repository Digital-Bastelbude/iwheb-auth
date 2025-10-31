# Deployment Guide

Complete guide for deploying iWheb Auth to shared hosting environments (Strato, Ionos, etc.)

## Overview

This guide shows how to deploy the **production-ready** application to your webspace. All build steps (composer install, optimization) are done locally, and only the necessary files are uploaded.

## Prerequisites

### Minimum Requirements:
- **PHP 7.2 or higher** (recommended: PHP 8.0+)
- PHP Extensions:
  - `pdo_sqlite` (SQLite database)
  - `sodium` (Encryption)
  - `json` (JSON processing)
  - `curl` (Webling API communication)
- Apache web server with `mod_rewrite`
- Write permissions for `storage/` and `logs/` directories

## Deployment Steps

### 1. Prepare Project Locally

Build the production-ready application on your local machine:

```bash
# Install dependencies (production only, optimized)
composer install --no-dev --optimize-autoloader --classmap-authoritative

# Optional: Run tests before deployment
composer install  # includes dev dependencies
vendor/bin/phpunit --testdox
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

### 2. Create Deployment Package

**Only upload these directories and files:**

```
deployment-package/
├── .htaccess                    # Security: Deny root access
├── config/
│   ├── config.json
│   ├── config-userauth.php
│   └── .secrets.php.example     # Template (rename on server)
├── logs/                        # Empty directory (will be created)
├── public/                      # ⚠️ IMPORTANT: DocumentRoot must point here
│   ├── .htaccess
│   └── index.php
├── src/UserAuth/                # Application source code (PSR-4)
│   ├── Auth/
│   ├── Database/
│   ├── Http/
│   ├── Exception/
│   └── logging.php
├── storage/                     # Empty directory (will be created)
│   └── ratelimit/               # Empty directory (will be created)
└── vendor/                      # ✅ From composer install --no-dev
    └── autoload.php
```

**❌ DO NOT UPLOAD:**
- `tests/` directory
- `phpunit.xml.dist`
- `.git/` directory
- `.gitignore`
- `composer.json` (optional, not needed on production)
- `composer.lock` (optional, not needed on production)
- `README.md` (optional)
- `doc/` directory (optional)
- `check-webspace.php` (optional, only for testing)

### 3. Upload to Webspace

Upload the prepared files via FTP/SFTP:

```bash
# Example using SFTP
sftp user@your-webspace.com
> cd /path/to/your/webspace
> put -r deployment-package/* .
> quit

# Or use FTP client (FileZilla, Cyberduck, etc.)
```

**Upload structure on server:**

```
/your-webspace/
├── .htaccess
├── config/
├── logs/                        # Create if not exists, chmod 755
├── public/                      # Set as DocumentRoot!
├── src/
├── storage/                     # Create if not exists, chmod 755
└── vendor/
```

### 3. Configure DocumentRoot

**Important:** The DocumentRoot must point to `/public/`!

#### For Strato/Ionos:
1. Open **Plesk/Webspace Administration**
2. Select **Domain/Subdomain**
3. **Change DocumentRoot** to: `/public`
4. Or create subdirectory: `https://yourdomain.com/api/` → `/path/to/project/public/`

### 4. Set Permissions

```bash
# Create directories if they don't exist and set permissions via FTP/SSH
mkdir -p storage/ratelimit
mkdir -p logs
chmod 755 storage/
chmod 755 logs/
chmod 755 storage/ratelimit/

# On some webspaces you may need:
chmod 777 storage/
chmod 777 logs/
```

### 5. Configure Application

#### Create config/.secrets.php on the server:

Rename `config/.secrets.php.example` to `config/.secrets.php` and edit:

```php
<?php
// Secrets - NEVER commit to Git!

// Webling API Access
putenv('WEBLING_DOMAIN=https://YOUR-SUBDOMAIN.webling.ch');
putenv('WEBLING_API_KEY=your-webling-api-key');

// Encryption Key (32 bytes, base64-encoded)
// Generate locally with: php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"
putenv('ENCRYPTION_KEY=base64:GENERATE_A_RANDOM_KEY');

// API Keys for access
$API_KEYS = [
    'your-api-key-here' => [
        'name' => 'Main API Key',
        'permissions' => ['*']  // Full access
    ]
];
```

**Security:** Set restrictive permissions on secrets file:
```bash
chmod 600 config/.secrets.php
```

#### Verify config/config.json:

```json
{
  "rateLimit": {
    "default": {
      "maxRequests": 100,
      "windowSeconds": 60
    }
  }
}
```

### 6. Set PHP Version

Most web hosting providers allow you to set the PHP version:

- **Strato:** Webspace Administration → PHP Settings → PHP 8.0+
- **Ionos:** MyIonos → Hosting → PHP Version → PHP 8.0+

### 7. Test Installation

Test the deployment:

```bash
# Via browser:
https://yourdomain.com/

# Expected response (without API key):
{"error":"Not Found","outcome":"NOT_FOUND","reason":"UNAUTHORIZED"}
```

**This means it's working!** The API requires a valid API key to access.

## Quick Deployment Script

Create a local script to automate the deployment preparation:

```bash
#!/bin/bash
# deploy-prepare.sh

echo "Preparing deployment package..."

# Clean up
rm -rf deployment-package/

# Install production dependencies
composer install --no-dev --optimize-autoloader --classmap-authoritative

# Create deployment structure
mkdir -p deployment-package/{config,logs,public,src,storage/ratelimit,vendor}

# Copy necessary files
cp -r vendor deployment-package/
cp -r src deployment-package/
cp -r public deployment-package/
cp config/config.json deployment-package/config/
cp .htaccess deployment-package/

# Create secrets template
cat > deployment-package/config/.secrets.php.example << 'EOF'
<?php
// Copy this file to .secrets.php and configure your settings

putenv('WEBLING_DOMAIN=https://your-subdomain.webling.ch');
putenv('WEBLING_API_KEY=your-api-key');
putenv('ENCRYPTION_KEY=base64:generate-random-key');

$API_KEYS = [
    'your-api-key' => [
        'name' => 'Main API Key',
        'permissions' => ['*']
    ]
];
EOF

echo "✓ Deployment package created in deployment-package/"
echo "  Upload the contents of deployment-package/ to your webspace"
echo ""
echo "Next steps:"
echo "  1. Upload deployment-package/* to your webspace"
echo "  2. Set DocumentRoot to /public/"
echo "  3. Copy .secrets.php.example to .secrets.php and configure"
echo "  4. chmod 755 storage/ logs/"
```

Make executable and run:
```bash
chmod +x deploy-prepare.sh
./deploy-prepare.sh
```

## Common Issues

### 1. "500 Internal Server Error"
- **Solution:** Check PHP error log (usually via hosting provider panel)
- Verify that `vendor/autoload.php` exists and is readable
- Check that all paths in `public/index.php` are correct
- Ensure PHP version is 7.2 or higher

### 2. "Class not found"
- **Solution:** The `vendor/` directory was not uploaded or is incomplete
- Re-run `composer install --no-dev` locally and upload `vendor/` again
- Verify that `vendor/autoload.php` exists

### 3. Database error "Unable to open database"
- **Solution:** Check write permissions on `storage/` (chmod 755 or 777)
- Ensure the directory exists: `mkdir -p storage`
- Verify SQLite extension is enabled in PHP

### 4. ".htaccess is ignored"
- **Solution:** mod_rewrite must be enabled (usually default on web hosting)
- Contact hosting provider to enable mod_rewrite
- For Ionos: Check "Apache Modules" in administration panel

### 5. "sodium extension not found"
- **Solution:** Use PHP 7.2+ which includes sodium by default
- Contact hosting provider to enable sodium extension
- On older hosting: Upgrade PHP version to 8.0+

### 6. "Composer not installed" (on server)
- **Solution:** You don't need Composer on the server!
- Run `composer install` locally and upload the `vendor/` directory
- The server only needs PHP to run the application

## Security

### Important for Production:

1. **Protect sensitive directories:**
   - `.htaccess` in root (prevents access to `/config`, `/src`, etc.)
   - Only `/public` should be publicly accessible

2. **Secrets not in Git:**
   - `config/.secrets.php` is in `.gitignore`
   - Never commit API keys or passwords

3. **Use HTTPS:**
   - Enable SSL certificate (Let's Encrypt often free)
   - Enable HTTPS redirect in `.htaccess`

4. **Monitor logs:**
   - Regularly check `logs/api.log`
   - React to suspicious activities

## Performance Optimization

### Production Build (Already Included)

The deployment uses an optimized autoloader:

```bash
# This is already done in step 1
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

This creates:
- ✓ Optimized class loading
- ✓ No development dependencies
- ✓ Authoritative classmap (faster lookups)

### OPcache

OPcache is usually already active on most web hosting. To verify, create a temporary `info.php`:

```php
<?php phpinfo();
```

Look for "Zend OPcache" - should show "Opcode Caching" as enabled.

**Important:** Delete `info.php` after checking!

## Webspace Compatibility Check

You can optionally upload `check-webspace.php` to verify server compatibility:

```bash
# Upload check-webspace.php to your webspace
# Run via browser or SSH:
php check-webspace.php
```

This will verify:
- ✓ PHP version (>= 7.2)
- ✓ Required extensions (PDO, Sodium, JSON, cURL)
- ✓ Write permissions (storage/, logs/)
- ✓ Composer autoloader presence

**Note:** This file is optional and only for verification. Remove it after deployment.

## Support

When encountering issues:
1. Check PHP error log
2. Examine `logs/api.log`
3. Verify PHP version and extensions: Create `info.php` with `<?php phpinfo();`

## Alternative: Subdomain

If DocumentRoot cannot be changed:

```bash
# Create subdomain: api.yourdomain.com
# Assign DocumentRoot to: /path/to/project/public/
```

## Deployment Checklist

### Local Preparation:
- [ ] Run `composer install --no-dev --optimize-autoloader --classmap-authoritative`
- [ ] Verify application works locally: `php -S localhost:8080 -t public`
- [ ] Create deployment package (see Quick Deployment Script above)
- [ ] Generate encryption key: `php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"`

### Server Upload:
- [ ] Upload files: `public/`, `src/`, `vendor/`, `config/`, `.htaccess`
- [ ] Create directories: `storage/`, `storage/ratelimit/`, `logs/`
- [ ] Copy and configure `config/.secrets.php` (from .example)
- [ ] Set permissions: `chmod 755 storage/ logs/` (or 777 if needed)

### Server Configuration:
- [ ] Configure DocumentRoot to `/public/` directory
- [ ] Set PHP version to 7.2+ (preferably 8.0+)
- [ ] Verify PHP extensions: pdo_sqlite, sodium, json, curl
- [ ] Test API endpoint in browser (should return 401/404 without API key)

### Production Security:
- [ ] Set `chmod 600 config/.secrets.php`
- [ ] Verify `.htaccess` in root denies access to non-public files
- [ ] Enable HTTPS and configure redirect in `public/.htaccess`
- [ ] Remove `check-webspace.php` if uploaded
- [ ] Remove any `info.php` or test files
- [ ] Monitor `logs/api.log` regularly

### Post-Deployment:
- [ ] Test with valid API key
- [ ] Verify session creation works
- [ ] Check log files for errors
- [ ] Set up log rotation if needed

## Advanced Configuration

### Custom PHP Settings

Some web hosting providers allow custom `php.ini` or `.user.ini` in the `public/` directory:

```ini
; .user.ini
memory_limit = 128M
upload_max_filesize = 10M
post_max_size = 10M
max_execution_time = 30
display_errors = Off
log_errors = On
```

### Database Location

By default, the SQLite database is stored in `storage/data.db`. To change the location, modify `public/index.php`:

```php
const DATA_FILE = BASE_DIR . '/storage/custom-name.db';
```

### Log Rotation

For production environments, consider implementing log rotation to prevent log files from growing too large. Many web hosting providers offer automated log rotation.

## Troubleshooting

### Debug Mode

To enable detailed error messages during deployment (disable in production):

```php
// At the top of public/index.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
```

### Check File Permissions

```bash
# List permissions
ls -la storage/
ls -la logs/

# Verify ownership (if SSH access available)
ls -la public/
```

### Test Autoloader

Create a test file `public/test.php`:

```php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

echo "Autoloader works!\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Extensions: " . implode(', ', get_loaded_extensions()) . "\n";
```

Visit `https://yourdomain.com/test.php` and remove after testing.
