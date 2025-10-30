<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../routes-logic.php';

class RoutesLogicTest extends TestCase {
    
    // ================== Legacy Format Tests ==================

    public function testRunRoutesNoParamCallsHandler(): void {
        $called = [];
        $routes = [
            'items' => [
                'noParam' => ['GET' => function($vars, $body) use (&$called) {
                    $called = ['vars' => $vars, 'body' => $body];
                    return ['data' => ['list' => []]];
                }]
            ]
        ];

        $response = new class {
            public function readJsonBody() { return null; }
        };

        $res = run_routes($routes, '/items', 'GET', $response);
        $this->assertIsArray($res);
        $this->assertArrayHasKey('data', $res);
        $this->assertSame([], $res['data']['list']);
        $this->assertSame([], $called['vars']);
    }

    public function testRunRoutesWithParamCallsHandler(): void {
        $captured = [];
        $routes = [
            'widgets' => [
                'withParam' => ['GET' => function($vars, $body) use (&$captured) {
                    $captured = ['vars' => $vars, 'body' => $body];
                    return ['data' => ['item' => ['id' => $vars['id']]]];
                }]
            ]
        ];

        $response = new class {
            public function readJsonBody() { return null; }
        };

        $res = run_routes($routes, '/widgets/42', 'GET', $response);
        $this->assertSame(42, $captured['vars']['id']);
        $this->assertSame(42, $res['data']['item']['id']);
    }

    public function testHandlerReturnsInvalidValueThrows(): void {
        $this->expectException(Exception::class);
        $routes = [
            'things' => [
                'noParam' => ['GET' => function($vars, $body) { return 'not an array'; }]
            ]
        ];
        $response = new class { public function readJsonBody() { return null; } };
        run_routes($routes, '/things', 'GET', $response);
    }

    public function testUnknownRouteThrows(): void {
        $this->expectException(Exception::class);
        $routes = [];
        $response = new class { public function readJsonBody() { return null; } };
        run_routes($routes, '/missing', 'GET', $response);
    }

    public function testNonCallableHandlerThrows(): void {
        $this->expectException(Exception::class);
        $routes = [
            'nope' => [
                'noParam' => ['GET' => 'not_a_function']
            ]
        ];
        $response = new class { public function readJsonBody() { return null; } };
        run_routes($routes, '/nope', 'GET', $response);
    }

    public function testHandlerExceptionPropagates(): void {
        $this->expectException(Exception::class);
        $routes = [
            'boom' => [
                'noParam' => ['GET' => function() { throw new \Exception('boom'); }]
            ]
        ];
        $response = new class { public function readJsonBody() { return null; } };
        run_routes($routes, '/boom', 'GET', $response);
    }

    public function testPathWithTooManySegmentsThrows(): void {
        $this->expectException(Exception::class);
        $routes = [ 'a' => [ 'noParam' => ['GET' => function() { return ['data'=>[]]; } ] ] ];
        $response = new class { public function readJsonBody() { return null; } };
        run_routes($routes, '/a/b/c', 'GET', $response);
    }
    
    // ================== Pattern-Based Format Tests ==================
    
    public function testPatternBasedSimplePath(): void {
        $routes = [
            [
                'pattern' => '#^/api/users$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        return ['data' => ['users' => []], 'status' => 200];
                    }
                ]
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        $result = run_routes($routes, '/api/users', 'GET', $response);
        
        $this->assertEquals(200, $result['status']);
        $this->assertArrayHasKey('users', $result['data']);
    }
    
    public function testPatternBasedDeepPath(): void {
        $routes = [
            [
                'pattern' => '#^/test/me/items$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        return ['data' => ['message' => 'Deep path works'], 'status' => 200];
                    }
                ]
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        $result = run_routes($routes, '/test/me/items', 'GET', $response);
        
        $this->assertEquals('Deep path works', $result['data']['message']);
    }
    
    public function testPatternBasedWithNumericCapture(): void {
        $routes = [
            [
                'pattern' => '#^/api/users/(\d+)$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        // Numeric captures should be converted to int
                        return ['data' => ['userId' => $pathVars[0], 'type' => gettype($pathVars[0])], 'status' => 200];
                    }
                ]
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        $result = run_routes($routes, '/api/users/456', 'GET', $response);
        
        $this->assertEquals(456, $result['data']['userId']);
        $this->assertEquals('integer', $result['data']['type']);
    }
    
    public function testPatternBasedWithNamedPathVars(): void {
        $routes = [
            [
                'pattern' => '#^/api/users/(\d+)/posts/(\d+)$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        return ['data' => [
                            'userId' => $pathVars['userId'],
                            'postId' => $pathVars['postId']
                        ], 'status' => 200];
                    }
                ],
                'pathVars' => ['userId', 'postId']
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        $result = run_routes($routes, '/api/users/10/posts/20', 'GET', $response);
        
        $this->assertEquals(10, $result['data']['userId']);
        $this->assertEquals(20, $result['data']['postId']);
    }
    
    public function testPatternBasedWithStringCapture(): void {
        $routes = [
            [
                'pattern' => '#^/api/users/([a-z]+)$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        return ['data' => ['username' => $pathVars[0], 'type' => gettype($pathVars[0])], 'status' => 200];
                    }
                ]
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        $result = run_routes($routes, '/api/users/john', 'GET', $response);
        
        $this->assertEquals('john', $result['data']['username']);
        $this->assertEquals('string', $result['data']['type']);
    }
    
    public function testPatternBasedNotFound(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('NOT_FOUND');
        
        $routes = [
            [
                'pattern' => '#^/api/users$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        return ['data' => [], 'status' => 200];
                    }
                ]
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        run_routes($routes, '/api/posts', 'GET', $response);
    }
    
    public function testPatternBasedMethodNotAllowed(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('METHOD_NOT_ALLOWED');
        
        $routes = [
            [
                'pattern' => '#^/api/users$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        return ['data' => [], 'status' => 200];
                    }
                ]
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        run_routes($routes, '/api/users', 'DELETE', $response);
    }
    
    public function testPatternBasedMultipleRoutes(): void {
        $routes = [
            [
                'pattern' => '#^/api/users$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        return ['data' => ['type' => 'users-list'], 'status' => 200];
                    }
                ]
            ],
            [
                'pattern' => '#^/api/posts$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        return ['data' => ['type' => 'posts-list'], 'status' => 200];
                    }
                ]
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        $result1 = run_routes($routes, '/api/users', 'GET', $response);
        $this->assertEquals('users-list', $result1['data']['type']);
        
        $result2 = run_routes($routes, '/api/posts', 'GET', $response);
        $this->assertEquals('posts-list', $result2['data']['type']);
    }
    
    public function testPatternBasedComplexRegex(): void {
        $routes = [
            [
                'pattern' => '#^/api/v(\d+)/users/([a-z0-9_]+)/posts/(\d+)$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        return [
                            'data' => [
                                'version' => $pathVars['version'],
                                'username' => $pathVars['username'],
                                'postId' => $pathVars['postId']
                            ],
                            'status' => 200
                        ];
                    }
                ],
                'pathVars' => ['version', 'username', 'postId']
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        $result = run_routes($routes, '/api/v2/users/john_doe/posts/42', 'GET', $response);
        
        $this->assertEquals(2, $result['data']['version']);
        $this->assertEquals('john_doe', $result['data']['username']);
        $this->assertEquals(42, $result['data']['postId']);
    }
    
    public function testPatternBasedMultipleMethodsOnSameRoute(): void {
        $routes = [
            [
                'pattern' => '#^/api/resource$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        return ['data' => ['method' => 'GET'], 'status' => 200];
                    },
                    'POST' => function($pathVars, $body) {
                        return ['data' => ['method' => 'POST'], 'status' => 201];
                    },
                    'PUT' => function($pathVars, $body) {
                        return ['data' => ['method' => 'PUT'], 'status' => 200];
                    }
                ]
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        $resultGet = run_routes($routes, '/api/resource', 'GET', $response);
        $this->assertEquals('GET', $resultGet['data']['method']);
        
        $resultPost = run_routes($routes, '/api/resource', 'POST', $response);
        $this->assertEquals('POST', $resultPost['data']['method']);
        
        $resultPut = run_routes($routes, '/api/resource', 'PUT', $response);
        $this->assertEquals('PUT', $resultPut['data']['method']);
    }
    
    public function testPatternBasedInvalidHandlerResponse(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('INVALID_HANDLER_RESPONSE');
        
        $routes = [
            [
                'pattern' => '#^/api/broken$#',
                'methods' => [
                    'GET' => function($pathVars, $body) {
                        // Invalid response - missing 'data' key
                        return ['wrong' => 'format'];
                    }
                ]
            ]
        ];
        
        $response = new class { public function readJsonBody() { return null; } };
        
        run_routes($routes, '/api/broken', 'GET', $response);
    }
    
    public function testEmptyArrayBodyThrowsInvalidInput(): void {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('INVALID_INPUT');
        
        $routes = [
            'items' => [
                'noParam' => [
                    'POST' => function($pathVars, $body) {
                        return ['data' => [], 'status' => 200];
                    }
                ]
            ]
        ];
        
        $response = new class {
            public function readJsonBody() { return []; }
        };
        
        run_routes($routes, '/items', 'POST', $response);
    }
    
    public function testValidJsonBodyIsPassed(): void {
        $requestData = ['name' => 'Test Item', 'quantity' => 5];
        $capturedBody = null;
        
        $routes = [
            'items' => [
                'noParam' => [
                    'POST' => function($pathVars, $body) use (&$capturedBody) {
                        $capturedBody = $body;
                        return ['data' => $body, 'status' => 201];
                    }
                ]
            ]
        ];
        
        $response = new class($requestData) {
            private $data;
            public function __construct($data) { $this->data = $data; }
            public function readJsonBody() { return $this->data; }
        };
        
        $result = run_routes($routes, '/items', 'POST', $response);
        
        $this->assertEquals($requestData, $result['data']);
        $this->assertEquals($requestData, $capturedBody);
    }
}
