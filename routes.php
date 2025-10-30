<?php

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/weblingclient.php';
require_once __DIR__ . '/uidencryptor.php';

use App\Security\UidEncryptor;

// -------- Routes --------
// instantiate helpers / services (assumes $CONFIG exists in bootstrap)
$authorizer = new Authorizer($CONFIG ?? []);
$dbService  = Database::getInstance();
$response   = Response::getInstance();

// Initialize Webling client and UidEncryptor from environment
$weblingDomain = getenv('WEBLING_DOMAIN') ?: 'demo';
$weblingApiKey = getenv('WEBLING_API_KEY') ?: '';
$weblingClient = new WeblingClient($weblingDomain, $weblingApiKey);

$encryptionKey = getenv('ENCRYPTION_KEY');
if ($encryptionKey && strpos($encryptionKey, 'base64:') === 0) {
    $encryptionKey = UidEncryptor::loadKeyFromEnv('ENCRYPTION_KEY');
} else {
    // Fallback: generate a temporary key (WARNING: not persistent!)
    $encryptionKey = UidEncryptor::generateKey();
}
$uidEncryptor = new UidEncryptor($encryptionKey, 'iwheb-auth');

// Authorize once for the current request and path. Authorizer will call
// Response::notFound() and exit on failure, so below we only run routes when
// authorization succeeded and returned an auth array.
try {
    $auth = $authorizer->authorize($METHOD, $PATH);
} catch (AuthorizationException $e) {
    // Convert authorization failure to the existing response/logging behaviour
    Response::getInstance()->notFound($e->key ?? null, $e->reason);
}

// Define route map. Base name -> modes (noParam/withParam) -> HTTP method -> handler (callable/closure)
$routes = [
    'login' => [
        'noParam' => [
            'POST' => function($pathVars, $body) use ($dbService, $weblingClient, $uidEncryptor, $auth) {
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

                // Create session for user (generates code automatically)
                $session = $dbService->createSession($token);

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
    'validate' => [
        'withParam' => [
            'POST' => function($pathVars, $body) use ($dbService, $auth) {
                // Session ID comes from URL parameter
                $sessionId = $pathVars['id'] ?? null;
                
                if (!$sessionId) {
                    throw new InvalidInputException('INVALID_INPUT', 'session_id required in URL');
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
                        'session_expires_at' => $session['expires_at'],
                        'validated' => true
                    ],
                    'status' => 200
                ];
            }
        ]
    ],
    'user' => [
        'withParam' => [
            'POST' => function($pathVars, $body) use ($dbService, $weblingClient, $uidEncryptor, $auth) {
                // Session ID comes from URL parameter
                $sessionId = $pathVars['id'] ?? null;
                
                if (!$sessionId) {
                    throw new InvalidInputException('INVALID_INPUT', 'session_id required in URL');
                }

                // Check if session is active (not expired)
                if (!$dbService->isSessionActive($sessionId)) {
                    throw new InvalidSessionException();
                }

                // Get session to retrieve user token
                $session = $dbService->getSessionBySessionId($sessionId);
                
                if (!$session) {
                    throw new InvalidSessionException('Session not found');
                }

                // Decrypt user token to get Webling user ID
                try {
                    $weblingUserId = $uidEncryptor->decrypt($session['user_token']);
                } catch (\Exception $e) {
                    throw new StorageException('DECRYPTION_ERROR', 'Failed to decrypt user token');
                }
                
                // Fetch user data from Webling
                try {
                    $weblingUser = $weblingClient->getUserDataById((int)$weblingUserId);
                } catch (WeblingException $e) {
                    throw new UserNotFoundException('Webling API error: ' . $e->getMessage());
                }
                
                if ($weblingUser === null) {
                    throw new UserNotFoundException();
                }

                // Touch user to refresh session and update last activity
                $newSessionId = $dbService->touchUser($sessionId);

                if (!$newSessionId) {
                    throw new StorageException('STORAGE_ERROR', 'Failed to refresh session');
                }

                return [
                    'data' => [
                        'session_id' => $newSessionId,
                        'session_expires_at' => $session['expires_at'],
                        'user' => $weblingUser
                    ],
                    'status' => 200
                ];
            }
        ]
    ],
    'session/touch' => [
        'withParam' => [
            'POST' => function($pathVars, $body) use ($dbService, $auth) {
                // Session ID comes from URL parameter
                $sessionId = $pathVars['id'] ?? null;
                
                if (!$sessionId) {
                    throw new InvalidInputException('INVALID_INPUT', 'session_id required in URL');
                }

                // Check if session is active (not expired)
                if (!$dbService->isSessionActive($sessionId)) {
                    throw new InvalidSessionException('Session invalid or expired');
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
    'session/logout' => [
        'withParam' => [
            'POST' => function($pathVars, $body) use ($dbService, $auth) {
                // Session ID comes from URL parameter
                $sessionId = $pathVars['id'] ?? null;
                
                if (!$sessionId) {
                    throw new InvalidInputException('INVALID_INPUT', 'session_id required in URL');
                }

                // Delete the session
                $deleted = $dbService->deleteSession($sessionId);
                
                if (!$deleted) {
                    throw new InvalidSessionException('Session not found');
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