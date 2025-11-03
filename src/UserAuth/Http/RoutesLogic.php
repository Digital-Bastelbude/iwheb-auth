<?php
declare(strict_types=1);

use IwhebAPI\UserAuth\Exception\Http\InvalidInputException;

/**
 * Dispatcher: given the pattern-based routes array, the current path and method,
 * call the matching handler and send the response (or map errors to notFound).
 *
 * Routes format (pattern-based only):
 * [
 *   [
 *     'pattern' => '#^/api/v1/items$#',
 *     'methods' => ['GET' => function($pathVars, $body) { ... }]
 *   ],
 *   [
 *     'pattern' => '#^/api/v1/items/(\d+)$#',
 *     'methods' => ['GET' => function($pathVars, $body) { ... }],
 *     'pathVars' => ['id'] // Optional: name for captured groups
 *   ]
 * ]
 */
function run_routes(array $routes, string $path, string $method, $response): array {
    // read request body once and pass to handlers
    $body = $response->readJsonBody();

    // Process pattern-based routes
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