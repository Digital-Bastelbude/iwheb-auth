<?php

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/weblingclient.php';
require_once __DIR__ . '/uidencryptor.php';
require_once __DIR__ . '/apikeymanager.php';

use App\Security\UidEncryptor;

// -------- Routes --------
// instantiate helpers / services (assumes $CONFIG exists in bootstrap)
$authorizer = new Authorizer($CONFIG ?? []);
$dbService  = Database::getInstance();
$response   = Response::getInstance();

// Initialize Webling client and UidEncryptor from environment variables
// Environment variables are set in .secrets.php which is loaded in public/index.php
$weblingDomain = getenv('WEBLING_DOMAIN');
$weblingApiKey = getenv('WEBLING_API_KEY');

if (!$weblingDomain || !$weblingApiKey) {
    throw new \RuntimeException('WEBLING_DOMAIN and WEBLING_API_KEY environment variables must be set in .secrets.php');
}

$weblingClient = new WeblingClient($weblingDomain, $weblingApiKey);

// Load encryption key from environment (must be in 'base64:...' format)
$encryptionKey = getenv('ENCRYPTION_KEY');
if (!$encryptionKey || strpos($encryptionKey, 'base64:') !== 0) {
    throw new \RuntimeException('ENCRYPTION_KEY environment variable must be set in .secrets.php with base64: prefix');
}

$uidEncryptor = new UidEncryptor(UidEncryptor::loadKeyFromEnv('ENCRYPTION_KEY'), 'iwheb-auth');

// Load API keys from $API_KEYS global (set in .secrets.php)
if (!isset($API_KEYS) || !is_array($API_KEYS)) {
    throw new \RuntimeException('API_KEYS must be defined in .secrets.php');
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
// Response::notFound() and exit on failure, so below we only run routes when
// authorization succeeded and returned an auth array.
try {
    $auth = $authorizer->authorize($METHOD, $PATH);
} catch (AuthorizationException $e) {
    // Convert authorization failure to the existing response/logging behaviour
    Response::getInstance()->notFound($e->key ?? null, $e->reason);
}

// Define routes using pattern-based format for flexible paths
$routes = [
    // POST /login
    [
        'pattern' => '#^/login$#',
        'methods' => [
            'POST' => function($pathVars, $body) use ($dbService, $weblingClient, $uidEncryptor, $auth, $apiKey) {
                // Validate input
                if (!isset($body['email'])) {
                    throw new InvalidInputException('INVALID_INPUT', 'Email required');
                }

                // Decode base64 URL-safe encoded email
                $encodedEmail = $body['email'];
                $email = base64_decode(strtr($encodedEmail, '-_', '+/'), true);
                
                if ($email === false || empty($email)) {
                    throw new InvalidInputException('INVALID_INPUT', 'Invalid email encoding');
                }

                // Check if user exists in Webling
                $weblingUserId = $weblingClient->getUserIdByEmail($email);
                
                if ($weblingUserId === null) {
                    throw new UserNotFoundException();
                }

                // Generate token from Webling user ID
                $token = $uidEncryptor->encrypt((string)$weblingUserId);

                // Check if user already exists in database
                $existingUser = $dbService->getUserByToken($token);
                
                if (!$existingUser) {
                    // Create new user (without code - code is in session now)
                    $dbService->createUser($token);
                }

                // Create session for user with API key (generates code automatically)
                $session = $dbService->createSession($token, $apiKey);

                return [
                    'data' => [
                        'session_id' => $session['session_id'],
                        'code' => $session['code'],
                        'code_expires_at' => $session['code_valid_until'],
                        'session_expires_at' => $session['expires_at']
                    ],
                    'status' => 200
                ];
            }
        ]
    ],
    // POST /validate/{session_id}
    [
        'pattern' => '#^/validate/([a-z0-9]+)$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'POST' => function($pathVars, $body) use ($dbService, $auth, $apiKey) {
                // Session ID comes from URL parameter
                $sessionId = $pathVars['session_id'];
                
                // Check if API key has access to this session
                if (!$dbService->checkSessionAccess($sessionId, $apiKey)) {
                    throw new InvalidSessionException();
                }
                
                // Validate input - code must be in body
                if (!isset($body['code'])) {
                    throw new InvalidInputException('INVALID_INPUT', 'code required');
                }

                $code = $body['code'];

                // Get session
                $session = $dbService->getSessionBySessionId($sessionId);
                
                if (!$session) {
                    throw new InvalidSessionException();
                }

                // Validate code for the session
                $isValidCode = $dbService->validateCode($sessionId, $code);
                
                if (!$isValidCode) {
                    throw new InvalidCodeException();
                }

                // Mark session as validated
                $dbService->validateSession($sessionId);

                // Touch user to generate new session ID
                $newSessionId = $dbService->touchUser($sessionId);

                if (!$newSessionId) {
                    throw new StorageException('STORAGE_ERROR', 'Failed to refresh session');
                }

                // Regenerate code for security (so old code can't be reused)
                $dbService->regenerateSessionCode($newSessionId);

                return [
                    'data' => [
                        'session_id' => $newSessionId,
                        'validated' => true,
                        'session_expires_at' => $session['expires_at']
                    ],
                    'status' => 200
                ];
            }
        ]
    ],
    // POST /user/{session_id}/info
    [
        'pattern' => '#^/user/([a-z0-9]+)/info$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'POST' => function($pathVars, $body) use ($dbService, $weblingClient, $uidEncryptor, $auth, $apiKey) {
                // Get session_id from path
                $sessionId = $pathVars['session_id'];
                
                // Check if API key has access to this session
                if (!$dbService->checkSessionAccess($sessionId, $apiKey)) {
                    throw new InvalidSessionException();
                }
                
                // Get session
                $session = $dbService->getSessionBySessionId($sessionId);
                
                if (!$session) {
                    throw new InvalidSessionException();
                }
                
                // Check if session is validated
                if (!$session['validated']) {
                    throw new InvalidSessionException();
                }

                // Get user
                $user = $dbService->getUserBySessionId($sessionId);
                
                if (!$user) {
                    throw new UserNotFoundException();
                }

                // Touch session to extend expiry
                $newSessionId = $dbService->touchUser($sessionId);
                
                if (!$newSessionId) {
                    throw new StorageException('STORAGE_ERROR', 'Failed to refresh session');
                }

                // Get weblingId (decrypt uid)
                $weblingId = $uidEncryptor->decrypt($user['uid']);

                // Fetch user data from Webling
                $weblingUser = $weblingClient->getUserDataById((int)$weblingId);

                if (!$weblingUser) {
                    throw new StorageException('WEBLING_ERROR', 'Failed to fetch user from Webling');
                }

                return [
                    'data' => [
                        'session_id' => $newSessionId,
                        'user' => $weblingUser,
                        'session_expires_at' => $session['expires_at']
                    ],
                    'status' => 200
                ];
            }
        ]
    ],
    // POST /user/{session_id}/token
    [
        'pattern' => '#^/user/([a-z0-9]+)/token$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'POST' => function($pathVars, $body) use ($dbService, $auth, $apiKey) {
                // Get session_id from path
                $sessionId = $pathVars['session_id'];
                
                // Check if API key has access to this session
                if (!$dbService->checkSessionAccess($sessionId, $apiKey)) {
                    throw new InvalidSessionException();
                }
                
                // Get session
                $session = $dbService->getSessionBySessionId($sessionId);
                
                if (!$session) {
                    throw new InvalidSessionException();
                }
                
                // Check if session is validated
                if (!$session['validated']) {
                    throw new InvalidSessionException();
                }

                // Get user
                $user = $dbService->getUserBySessionId($sessionId);
                
                if (!$user) {
                    throw new UserNotFoundException();
                }

                // Touch session to extend expiry
                $newSessionId = $dbService->touchUser($sessionId);
                
                if (!$newSessionId) {
                    throw new StorageException('STORAGE_ERROR', 'Failed to refresh session');
                }

                return [
                    'data' => [
                        'session_id' => $newSessionId,
                        'token' => $user['uid'],
                        'session_expires_at' => $session['expires_at']
                    ],
                    'status' => 200
                ];
            }
        ]
    ],
    // GET /session/check/{session_id}
    [
        'pattern' => '#^/session/check/([a-z0-9]+)$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'GET' => function($pathVars, $body) use ($dbService, $apiKey) {
                // Session ID comes from URL parameter
                $sessionId = $pathVars['session_id'];

                // Check if API key has access to this session
                if (!$dbService->checkSessionAccess($sessionId, $apiKey)) {
                    throw new InvalidSessionException();
                }

                // Get session
                $session = $dbService->getSessionBySessionId($sessionId);
                
                if (!$session) {
                    throw new InvalidSessionException();
                }

                // Check if session is validated and not expired
                if (!$session['validated'] || !$dbService->isSessionActive($sessionId)) {
                    throw new InvalidSessionException();
                }

                return [
                    'data' => [
                        'session_id' => $sessionId,
                        'expires_at' => $session['expires_at'],
                        'active' => true
                    ],
                    'status' => 200
                ];
            }
        ]
    ],
    // POST /session/touch/{session_id}
    [
        'pattern' => '#^/session/touch/([a-z0-9]+)$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'POST' => function($pathVars, $body) use ($dbService, $apiKey) {
                // Session ID comes from URL parameter
                $sessionId = $pathVars['session_id'];

                // Check if API key has access to this session
                if (!$dbService->checkSessionAccess($sessionId, $apiKey)) {
                    throw new InvalidSessionException();
                }

                // Check if session is active (not expired)
                if (!$dbService->isSessionActive($sessionId)) {
                    throw new InvalidSessionException();
                }

                // Touch user to refresh session and update last activity
                $newSessionId = $dbService->touchUser($sessionId);

                if (!$newSessionId) {
                    throw new StorageException('STORAGE_ERROR', 'Failed to refresh session');
                }

                // Get the new session to retrieve expires_at
                $newSession = $dbService->getSessionBySessionId($newSessionId);
                
                if (!$newSession) {
                    throw new StorageException('STORAGE_ERROR', 'Failed to retrieve new session');
                }

                return [
                    'data' => [
                        'session_id' => $newSessionId,
                        'expires_at' => $newSession['expires_at']
                    ],
                    'status' => 200
                ];
            }
        ]
    ],
    // POST /session/logout/{session_id}
    [
        'pattern' => '#^/session/logout/([a-z0-9]+)$#',
        'pathVars' => ['session_id'],
        'methods' => [
            'POST' => function($pathVars, $body) use ($dbService, $apiKey) {
                // Session ID comes from URL parameter
                $sessionId = $pathVars['session_id'];

                // Check if API key has access to this session
                if (!$dbService->checkSessionAccess($sessionId, $apiKey)) {
                    throw new InvalidSessionException();
                }

                // Delete the session
                $deleted = $dbService->deleteSession($sessionId);
                
                if (!$deleted) {
                    throw new InvalidSessionException();
                }

                return [
                    'data' => [],
                    'status' => 204  // No Content
                ];
            }
        ]
    ]
];

require_once __DIR__ . '/routes-logic.php';

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