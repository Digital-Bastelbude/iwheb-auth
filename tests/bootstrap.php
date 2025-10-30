<?php
// Basic bootstrap for tests: include Composer autoload if available and project files
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
	require_once $autoloader;
}

// Include lightweight project bootstrap for tests (Database etc.)
require_once __DIR__ . '/../storage.php';
require_once __DIR__ . '/../uidencryptor.php';

// During tests, convert deprecations and notices to exceptions to make them visible
// The strict test error handler is useful during development to make deprecations
// and notices fail tests so they are fixed quickly. Enable by setting the
// environment variable `TEST_STRICT_ERRORS=1` when running PHPUnit.
if (getenv('TEST_STRICT_ERRORS') === '1') {
	set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
		// Convert deprecations and notices into exceptions so PHPUnit reports them with traces
		if ($severity & (E_USER_DEPRECATED | E_DEPRECATED | E_USER_NOTICE | E_NOTICE)) {
			throw new \ErrorException($message, 0, $severity, $file, $line);
		}

		// Let other errors through to the normal handler
		return false;
	});
}
