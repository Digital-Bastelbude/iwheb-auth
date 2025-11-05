<?php
use PHPUnit\Framework\TestCase;
use IwhebAPI\UserAuth\Auth\{ApiKeyHelper, RateLimiter, Authorizer, AuthorizationException};

require_once __DIR__ . '/bootstrap.php';

class AccessTest extends TestCase {
    public function testGetApiKeyFromHeader(): void {
        $_SERVER['HTTP_X_API_KEY'] = 'header-key-1';
        $h = new ApiKeyHelper();
        $this->assertSame('header-key-1', $h->getApiKey());
        unset($_SERVER['HTTP_X_API_KEY']);
    }

    public function testGetApiKeyFromAuthorizationHeader(): void {
        $_SERVER['HTTP_AUTHORIZATION'] = 'ApiKey auth-123';
        $h = new ApiKeyHelper();
        $this->assertSame('auth-123', $h->getApiKey());
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testGetApiKeyFromQueryParam(): void {
        $_GET['api_key'] = 'qparam-key';
        $h = new ApiKeyHelper();
        $this->assertSame('qparam-key', $h->getApiKey());
        unset($_GET['api_key']);
    }

    public function testRouteMatches(): void {
        $h = new ApiKeyHelper();
        $this->assertTrue($h->routeMatches('GET:/items', 'GET', '/items'));
        $this->assertTrue($h->routeMatches('GET:/items/{id}', 'GET', '/items/123'));
        $this->assertFalse($h->routeMatches('GET:/items/{id}', 'GET', '/items/abc')); // id must be numeric
        $this->assertTrue($h->routeMatches('POST:/widgets/{name}', 'POST', '/widgets/foo'));
    }

    public function testRateLimiterAllowsThenBlocks(): void {
        $dir = sys_get_temp_dir() . '/rl_test_' . bin2hex(random_bytes(4));
        $rl = new RateLimiter($dir);
        $key = 'k1';
        // allow 2 requests in window of 2 seconds
        $this->assertTrue($rl->checkRateLimit($key, 2, 2));
        $this->assertTrue($rl->checkRateLimit($key, 2, 2));
        $this->assertFalse($rl->checkRateLimit($key, 2, 2));
        // cleanup files
        array_map('unlink', glob($dir . '/*.json') ?: []);
        @rmdir($dir);
    }

    public function testAuthorizerMissingKeyThrows(): void {
        $cfg = ['keys' => []];
        $auth = new Authorizer($cfg);
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('NO_KEY');
        $auth->authorize('GET', '/items', '');
    }

    public function testAuthorizerInvalidKeyThrows(): void {
        $cfg = ['keys' => []];
        $auth = new Authorizer($cfg);
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('INVALID_KEY');
        $auth->authorize('GET', '/items', 'unknown-key');
    }

    public function testAuthorizerNoPermissionThrows(): void {
        $cfg = ['keys' => ['k' => ['routes' => []], 'rate_limit' => ['default' => ['window_seconds' => 60, 'max_requests' => 100]]]];
        $auth = new Authorizer($cfg);
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('NO_PERMISSION');
        $auth->authorize('GET', '/items', 'k');
    }

    public function testAuthorizerRateLimitThrows(): void {
        $dir = sys_get_temp_dir() . '/rl_test_' . bin2hex(random_bytes(4));
        $cfg = ['keys' => ['rlkey' => ['routes' => ['GET:/foo'], 'rate_limit' => ['window_seconds' => 60, 'max_requests' => 1]]], 'rate_limit' => ['default' => ['window_seconds' => 60, 'max_requests' => 10]]];
        $rl = new RateLimiter($dir);
        $auth = new Authorizer($cfg, new ApiKeyHelper(), $rl);
        // first call allowed
        $res = $auth->authorize('GET', '/foo', 'rlkey');
        $this->assertSame('rlkey', $res['key']);
        // second call should throw rate limit
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('RATE_LIMIT');
        try { 
            $auth->authorize('GET', '/foo', 'rlkey'); 
        } finally { 
            array_map('unlink', glob($dir . '/*.json') ?: []); 
            @rmdir($dir); 
        }
    }
}
