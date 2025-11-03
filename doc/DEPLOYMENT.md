# Deployment Guide

Deploy iWheb Auth to production (shared hosting, VPS, cloud).

## Prerequisites

- PHP 8.1+ with extensions: `pdo_sqlite`, `sodium`, `json`, `curl`
- Apache with `mod_rewrite` or Nginx
- Write permissions for `storage/` and `logs/`

## Quick Deployment

### 1. Build Locally

```bash
# Production dependencies
composer install --no-dev --optimize-autoloader --classmap-authoritative

# Optional: Test before deployment
composer install && vendor/bin/phpunit
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

### 2. Upload Files

**Upload to server:**
```
/webspace/
├── .htaccess                    # Root security
├── config/
│   ├── config.json
│   ├── config-userauth.php
│   └── .secrets.php.example     # Rename to .secrets.php
├── logs/                        # chmod 755
├── public/                      # ⚠️ DocumentRoot points here!
│   ├── .htaccess
│   └── index.php
├── src/UserAuth/
├── storage/                     # chmod 755
│   └── ratelimit/
└── vendor/                      # From composer install --no-dev
```

**❌ Don't upload:** `tests/`, `.git/`, `phpunit.xml.dist`, `doc/`

### 3. Configure Server

**Set DocumentRoot:**
- Point web server to `/public/` directory
- Example: `https://yourdomain.com/` → `/path/to/project/public/`

**PHP Version:**
- Use PHP 8.1 or higher (check hosting panel)

### 4. Create Secrets File

```bash
# On server:
cd /path/to/project
cp config/.secrets.php.example config/.secrets.php
chmod 600 config/.secrets.php
```

Edit `config/.secrets.php`:
```php
<?php
putenv('WEBLING_DOMAIN=https://your-subdomain.webling.ch');
putenv('WEBLING_API_KEY=your-webling-api-key');
putenv('ENCRYPTION_KEY=base64:GENERATE_RANDOM_KEY');

// Generate key locally: php keygenerator.php encryption

$API_KEYS = [
    'your-api-key' => [
        'name' => 'Main API',
        'permissions' => ['user_info', 'user_token', 'delegate']
    ]
];
```

### 5. Set Permissions

```bash
chmod 755 storage/ logs/ storage/ratelimit/
# If needed: chmod 777 storage/ logs/
chmod 600 config/.secrets.php
```

### 6. Test

```bash
curl https://yourdomain.com/
# Expected: {"error":"Not Found",...}  (401/404 without API key = working!)

curl -H "X-API-Key: your-key" https://yourdomain.com/session/check/test
# Should return proper response
```

## Web Server Configuration

### Apache (.htaccess already included)

**Root `.htaccess`:**
```apache
# Deny access to sensitive directories
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^config/ - [F,L]
    RewriteRule ^src/ - [F,L]
    RewriteRule ^vendor/ - [F,L]
    RewriteRule ^storage/ - [F,L]
    RewriteRule ^logs/ - [F,L]
</IfModule>
```

### Nginx

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/project/public;
    index index.php;

    # Deny access to sensitive files
    location ~ ^/(config|src|vendor|storage|logs)/ {
        deny all;
        return 404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

## Deployment Script

Automate local preparation:

```bash
#!/bin/bash
# deploy.sh

echo "Building deployment package..."

# Install production dependencies
composer install --no-dev --optimize-autoloader --classmap-authoritative

# Create package
rm -rf deploy/
mkdir -p deploy/{config,logs,public,src,storage/ratelimit}

# Copy files
cp -r vendor deploy/
cp -r src deploy/
cp -r public deploy/
cp config/config.json deploy/config/
cp config/.secrets.php.example deploy/config/
cp .htaccess deploy/

echo "✓ Ready to upload: deploy/*"
echo ""
echo "Next steps:"
echo "  1. Upload deploy/* to server"
echo "  2. Set DocumentRoot to /public/"
echo "  3. Copy .secrets.php.example to .secrets.php"
echo "  4. chmod 755 storage/ logs/"
echo "  5. chmod 600 config/.secrets.php"
```

## Troubleshooting

### 500 Internal Server Error
- Check PHP error log
- Verify `vendor/autoload.php` exists
- Ensure PHP 8.1+

### Class Not Found
- Re-upload `vendor/` directory
- Run `composer install --no-dev` locally

### Database Error
- `chmod 755 storage/` (or 777 if needed)
- Verify SQLite extension enabled

### .htaccess Ignored (Apache)
- Enable `mod_rewrite`
- Contact hosting provider

### Session/Permission Issues
- Verify API key in `config/.secrets.php`
- Check case sensitivity
- Ensure `routes` or `permissions` array configured

## Security Checklist

- ✅ HTTPS enabled (SSL certificate)
- ✅ `config/.secrets.php` never committed to git
- ✅ `chmod 600 config/.secrets.php`
- ✅ DocumentRoot points to `/public/` only
- ✅ `.htaccess` denies access to config/src/vendor/
- ✅ Monitor `logs/api.log` regularly
- ✅ Strong encryption key (32 bytes)
- ✅ API key rotation schedule

## Performance

**OPcache:** Usually enabled by default on hosting. Verify with `phpinfo()`.

**Autoloader:** Already optimized with `--classmap-authoritative`.

**SQLite:** For high-traffic, consider migration to PostgreSQL/MySQL.

## Support

Check logs:
```bash
tail -f logs/api.log
tail -f /var/log/php/error.log  # or hosting provider's error log
```

Verify setup:
```bash
php -v                          # PHP version
php -m                          # Extensions
ls -la storage/ logs/           # Permissions
```

