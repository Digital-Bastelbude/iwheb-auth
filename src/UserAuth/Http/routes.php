<?php
declare(strict_types=1);

namespace IwhebAPI\UserAuth\Http;

use IwhebAPI\UserAuth\Database\{Database, UidEncryptor};
use IwhebAPI\UserAuth\Auth\{Authorizer, ApiKeyManager, AuthorizationException};
use IwhebAPI\UserAuth\Http\Controllers\{AuthController, SessionController, UserController};
use IwhebAPI\UserAuth\Exception\Http\InvalidInputException;
use IwhebAPI\UserAuth\Exception\Database\StorageException;
use IwhebAPI\UserAuth\Exception\NotFoundException;

// -------- Routes --------
// Instantiate helpers / services (assumes $CONFIG exists in bootstrap)
$authorizer = new Authorizer($CONFIG ?? []);
$dbService  = Database::getInstance();
$response   = Response::getInstance();

// Initialize Webling client and UidEncryptor from environment variables
// Environment variables are set in config/.secrets.php which is loaded in public/index.php
$weblingDomain = getenv('WEBLING_DOMAIN');
$weblingApiKey = getenv('WEBLING_API_KEY');

if (!$weblingDomain || !$weblingApiKey) {
    throw new \RuntimeException('WEBLING_DOMAIN and WEBLING_API_KEY environment variables must be set in config/.secrets.php');
}

$weblingClient = new WeblingClient($weblingDomain, $weblingApiKey);

// Load encryption key from environment (must be in 'base64:...' format)
$encryptionKey = getenv('ENCRYPTION_KEY');
if (!$encryptionKey || strpos($encryptionKey, 'base64:') !== 0) {
    throw new \RuntimeException('ENCRYPTION_KEY environment variable must be set in config/.secrets.php with base64: prefix');
}

$uidEncryptor = new UidEncryptor(UidEncryptor::loadKeyFromEnv('ENCRYPTION_KEY'), 'iwheb-auth');

// Load API keys from $API_KEYS global (set in config/.secrets.php)
if (!isset($API_KEYS)) {
    throw new \RuntimeException('API_KEYS must be defined in config/.secrets.php');
}

$apiKeyManager = new ApiKeyManager($API_KEYS);

// Extract and validate API key from request
$apiKey = ApiKeyManager::extractApiKeyFromRequest();

if (!$apiKey || !$apiKeyManager->isValidApiKey($apiKey)) {
    // Invalid or missing API key
    Response::getInstance()->notFound(null, 'UNAUTHORIZED');
}

// Check if API key has access to the requested route
if (!$apiKeyManager->canAccessRoute($apiKey, $PATH)) {
    // API key doesn't have permission for this route
    Response::getInstance()->notFound(null, 'FORBIDDEN');
}

// Authorize once for the current request and path. Authorizer will call
// API key validation and route access is already handled above
// Pass the API key to the authorize method for rate limiting and additional checks
try {
    $auth = $authorizer->authorize($METHOD, $PATH, $apiKey);
} catch (AuthorizationException $e) {
    // Convert authorization failure to the existing response/logging behaviour
    Response::getInstance()->notFound($e->key ?? null, $e->reason);
}

\error_log("DEBUG: authorized, key: {$auth['key']}");

// Instantiate controllers
$authController = new AuthController($dbService, $response, $authorizer, $apiKeyManager, $CONFIG, $apiKey, $weblingClient, $uidEncryptor);
$sessionController = new SessionController($dbService, $response, $authorizer, $apiKeyManager, $CONFIG, $apiKey);
$userController = new UserController($dbService, $response, $authorizer, $apiKeyManager, $CONFIG, $apiKey, $weblingClient, $uidEncryptor);

// Define routes using pattern-based format for flexible paths
$routes = [
    // POST /login
    [
        'pattern' => '#^/login$#',
        'methods' => [
            'POST' => [$authController, 'login']
        ]
    ],
    // POST /validate/{session_id}
    [
        'pattern' => '#^/validate/([a-z0-9]+)$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'POST' => [$authController, 'validate']
        ]
    ],
    // GET /user/{session_id}/info
    [
        'pattern' => '#^/user/([a-z0-9]+)/info$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'GET' => [$userController, 'getInfo']
        ]
    ],
    // GET /user/{session_id}/token
    [
        'pattern' => '#^/user/([a-z0-9]+)/token$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'GET' => [$userController, 'getToken']
        ]
    ],
    // GET /session/check/{session_id}
    [
        'pattern' => '#^/session/check/([a-z0-9]+)$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'GET' => [$sessionController, 'check']
        ]
    ],
    // POST /session/touch/{session_id}
    [
        'pattern' => '#^/session/touch/([a-z0-9]+)$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'POST' => [$sessionController, 'touch']
        ]
    ],
    // POST /session/delegate/{session_id}
    [
        'pattern' => '#^/session/delegate/([a-zA-Z0-9]+)$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'POST' => [$sessionController, 'createDelegated']
        ]
    ],
    // POST /session/logout/{session_id}
    [
        'pattern' => '#^/session/logout/([a-z0-9]+)$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'POST' => [$authController, 'logout']
        ]
    ]
];

require_once __DIR__ . '/RoutesLogic.php';

try {
    $result = run_routes($routes, $PATH, $METHOD, $response);
    if (is_array($result) && array_key_exists('data', $result)) {
        $response->sendJson($result['data'], $result['status'] ?? 200, $result['headers'] ?? [], $result['outcome'] ?? 'ALLOW', $result['reason'] ?? 'OK', $result['key'] ?? $auth['key'] ?? null);
    } else {
        $response->notFound($auth['key'] ?? null, 'NOT_FOUND');
    }
} catch (StorageException $e) {
    // storage errors map to STORAGE_ERROR (500 Internal Server Error)
    $response->notFound($auth['key'] ?? null, 'STORAGE_ERROR');
} catch (InvalidInputException $e) {
    // Invalid input should produce a 400 Bad Request response
    $response->sendJson(['error' => 'Invalid input'], 400, [], 'DENY', 'INVALID_INPUT', $auth['key'] ?? null);
} catch (NotFoundException $e) {
    // All NotFoundException and subclasses (InvalidSessionException, InvalidCodeException, UserNotFoundException)
    // are mapped to a generic 404 response for security
    $response->notFound($auth['key'] ?? null, 'NOT_FOUND');
} catch (\Exception $e) {
    // propagate auth info for notFound
    $reason = $e->getMessage() ?: 'NOT_FOUND';
    $response->notFound($auth['key'] ?? null, $reason);
}
