<?php
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/exceptions.php';

/**
 * Dispatcher: given the routes dictionary, the current path and method,
 * call the matching handler and send the response (or map errors to notFound).
 *
 * Routes format supports two structures:
 * 
 * 1. Legacy format (simple paths):
 * [
 *   'items' => [
 *     'noParam' => ['GET' => 'items_list', 'POST' => 'items_create'],
 *     'withParam' => ['GET' => 'items_get', 'PUT' => 'items_put']
 *   ]
 * ]
 * 
 * 2. Pattern-based format (flexible paths):
 * [
 *   [
 *     'pattern' => '#^/api/v1/items$#',
 *     'methods' => ['GET' => function($matches, $body) { ... }]
 *   ],
 *   [
 *     'pattern' => '#^/api/v1/items/(\d+)$#',
 *     'methods' => ['GET' => function($matches, $body) { ... }],
 *     'pathVars' => ['id'] // Optional: name for captured groups
 *   ]
 * ]
 */
function run_routes(array $routes, string $path, string $method, $response): array {
    // read request body once and pass to handlers
    $body = $response->readJsonBody();

    // If the body is an empty array, treat this as invalid input for handlers
    if (is_array($body) && count($body) === 0) {
        throw new InvalidInputException('INVALID_INPUT');
    }

    // Check if routes use pattern-based format (array of arrays with 'pattern' key)
    $isPatternBased = isset($routes[0]) && is_array($routes[0]) && isset($routes[0]['pattern']);
    
    if ($isPatternBased) {
        // Pattern-based routing
        foreach ($routes as $route) {
            if (!isset($route['pattern']) || !isset($route['methods'])) {
                continue;
            }
            
            if (preg_match($route['pattern'], $path, $matches)) {
                $handler = $route['methods'][$method] ?? null;
                
                if (!$handler || !is_callable($handler)) {
                    throw new \Exception('METHOD_NOT_ALLOWED');
                }
                
                // Build pathVars from captured groups
                $pathVars = [];
                if (isset($route['pathVars']) && is_array($route['pathVars'])) {
                    // Named path variables
                    foreach ($route['pathVars'] as $index => $name) {
                        if (isset($matches[$index + 1])) {
                            $pathVars[$name] = $matches[$index + 1];
                            // Convert to int if it's numeric
                            if (ctype_digit($pathVars[$name])) {
                                $pathVars[$name] = (int)$pathVars[$name];
                            }
                        }
                    }
                } else {
                    // Unnamed: just pass numeric indexes (backward compatible)
                    for ($i = 1; $i < count($matches); $i++) {
                        $pathVars[$i - 1] = $matches[$i];
                        if (ctype_digit($pathVars[$i - 1])) {
                            $pathVars[$i - 1] = (int)$pathVars[$i - 1];
                        }
                    }
                }
                
                $result = call_user_func($handler, $pathVars, $body);
                
                if (is_array($result) && array_key_exists('data', $result)) {
                    return $result;
                }
                
                throw new \Exception('INVALID_HANDLER_RESPONSE');
            }
        }
        
        throw new \Exception('NOT_FOUND');
    }
    
    // Legacy routing (backward compatible)
    $pathVars = [];
    if (preg_match('#^/([^/]+)/(\d+)$#', $path, $m)) {
        $base = $m[1];
        $pathVars['id'] = (int)$m[2];
        $mode = 'withParam';
    } elseif (preg_match('#^/([^/]+)$#', $path, $m)) {
        $base = $m[1];
        $mode = 'noParam';
    } else {
        throw new \Exception('NOT_FOUND');
    }

    if (!isset($routes[$base])) {
        throw new \Exception('NOT_FOUND');
    }

    $mapping = $routes[$base];
    if (!isset($mapping[$mode]) || !is_array($mapping[$mode])) {
        throw new \Exception('NOT_FOUND');
    }

    $handler = $mapping[$mode][$method] ?? null;
    // handler must be a callable (closure or function name)
    if (!$handler || !is_callable($handler)) {
        throw new \Exception('NOT_FOUND');
    }

    $result = call_user_func($handler, $pathVars, $body);

    if (is_array($result) && array_key_exists('data', $result)) {
        return $result;
    }
    // If handler returned something unexpected, signal not found to caller
    throw new \Exception('NOT_FOUND');
}