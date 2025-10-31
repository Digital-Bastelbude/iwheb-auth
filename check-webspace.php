#!/usr/bin/env php
<?php
/**
 * Webspace Compatibility Checker
 * Checks if the webspace meets all requirements
 */

echo "=== iWheb Auth - Webspace Compatibility Check ===\n\n";

$errors = [];
$warnings = [];
$ok = [];

// 1. PHP Version
echo "1. PHP Version: ";
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '7.2.0', '>=')) {
    echo "✓ $phpVersion (OK)\n";
    $ok[] = "PHP Version $phpVersion";
} else {
    echo "✗ $phpVersion (Minimum 7.2 required!)\n";
    $errors[] = "PHP Version too old: $phpVersion (requires >= 7.2)";
}

// 2. PDO SQLite
echo "2. PDO SQLite: ";
if (extension_loaded('pdo_sqlite')) {
    echo "✓ Installed\n";
    $ok[] = "PDO SQLite Extension";
} else {
    echo "✗ Not available\n";
    $errors[] = "PDO SQLite Extension missing";
}

// 3. Sodium
echo "3. Sodium (Encryption): ";
if (extension_loaded('sodium')) {
    echo "✓ Installed\n";
    $ok[] = "Sodium Extension";
} else {
    echo "✗ Not available\n";
    $errors[] = "Sodium Extension missing (required for encryption)";
}

// 4. JSON
echo "4. JSON: ";
if (extension_loaded('json')) {
    echo "✓ Installed\n";
    $ok[] = "JSON Extension";
} else {
    echo "✗ Not available\n";
    $errors[] = "JSON Extension missing";
}

// 5. cURL
echo "5. cURL (Webling API): ";
if (extension_loaded('curl')) {
    echo "✓ Installed\n";
    $ok[] = "cURL Extension";
} else {
    echo "✗ Not available\n";
    $errors[] = "cURL Extension missing (required for Webling API)";
}

// 6. Write permissions storage/
echo "6. Write permissions storage/: ";
$storageDir = __DIR__ . '/storage';
if (is_dir($storageDir) && is_writable($storageDir)) {
    echo "✓ Writable\n";
    $ok[] = "storage/ directory writable";
} else {
    echo "✗ Not writable\n";
    $errors[] = "storage/ directory not writable (chmod 755 or 777)";
}

// 7. Write permissions logs/
echo "7. Write permissions logs/: ";
$logsDir = __DIR__ . '/logs';
if (is_dir($logsDir) && is_writable($logsDir)) {
    echo "✓ Writable\n";
    $ok[] = "logs/ directory writable";
} else {
    echo "✗ Not writable\n";
    $errors[] = "logs/ directory not writable (chmod 755 or 777)";
}

// 8. Composer Autoloader
echo "8. Composer Autoloader: ";
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    echo "✓ Found\n";
    $ok[] = "Composer autoload.php present";
} else {
    echo "✗ Not found\n";
    $errors[] = "vendor/autoload.php missing (run 'composer install')";
}

// 9. Config File
echo "9. Config .secrets.php: ";
$secrets = __DIR__ . '/config/.secrets.php';
if (file_exists($secrets)) {
    echo "✓ Found\n";
    $ok[] = "config/.secrets.php present";
} else {
    echo "⚠ Not found\n";
    $warnings[] = "config/.secrets.php missing (must be created)";
}

// 10. Memory Limit
echo "10. Memory Limit: ";
$memLimit = ini_get('memory_limit');
echo "$memLimit\n";
if ($memLimit === '-1' || (int)$memLimit >= 128) {
    $ok[] = "Memory Limit sufficient: $memLimit";
} else {
    $warnings[] = "Memory Limit low: $memLimit (recommended: 128M)";
}

// Summary
echo "\n=== Summary ===\n";
echo "✓ OK: " . count($ok) . "\n";
echo "⚠ Warnings: " . count($warnings) . "\n";
echo "✗ Errors: " . count($errors) . "\n\n";

if (!empty($warnings)) {
    echo "Warnings:\n";
    foreach ($warnings as $w) {
        echo "  ⚠ $w\n";
    }
    echo "\n";
}

if (!empty($errors)) {
    echo "Errors (must be fixed!):\n";
    foreach ($errors as $e) {
        echo "  ✗ $e\n";
    }
    echo "\n";
    exit(1);
}

echo "✓ System meets all requirements!\n";
echo "  The project should work on this webspace.\n\n";
exit(0);
