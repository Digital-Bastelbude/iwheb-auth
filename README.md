# PHP REST Demo (logging, API keys, rate limits)

Small, dependency-free REST API in plain PHP. This project demonstrates:

- API-key based authorization with per-key route scopes
- Per-key rate limits with a global default
- Simple JSON line logging for every request

## Project layout

```
php-rest-logging/
  public/
    index.php        # front controller, sets $METHOD/$PATH and bootstraps
  config/
    config.json      # keys, routes and rate limit settings
  storage/
    data.json        # persistent app data
    ratelimit/       # per-key rate-limit state files
  logs/
    api.log          # JSON-per-line request log
  routes.php         # central routing / dispatch (edit this to add routes)
  access.php         # authorization / rate-limit logic
  response.php       # response helper and logging
  storage.php        # simple file-based storage wrapper
  logging.php        # logger helper
  tests/             # unit tests
  README.md
```

## Run (development)

Use PHP's built-in server:

```bash
php -S localhost:8080 -t public
```

## Config (`config/config.json`)

`config/config.json` contains the global defaults and per-key entries. Important fields:

- `rate_limit.default` — default window_seconds and max_requests
- `keys` — map of API keys to allowed `routes`, `scopes` and optional per-key `rate_limit`

Example snippet:

```json
{
  "rate_limit": { "default": { "window_seconds": 60, "max_requests": 100 } },
  "keys": {
    "demo-key-123": {
      "routes": ["GET:/items","GET:/items/{id}","POST:/items","PUT:/items/{id}"],
      "scopes": ["read","write"],
      "rate_limit": { "window_seconds": 60, "max_requests": 10 }
    }
  }
}
```

Routes are expressed as `METHOD:/path` where `{id}` is a numeric parameter recognized by the authorizer.

## Logging

Logs are written to `logs/api.log` — one JSON object per request. Fields include timestamp, IP,
method, path, response status, outcome (ALLOW or DENY), key and reason. This format is convenient
for downstream parsing and integrating with tools like Fail2Ban.

## How to add routes (edit `routes.php`)

`routes.php` contains simple dispatch logic using `$METHOD` and `$PATH`. Authorization is run once
at the top and on failure the authorizer calls `Response::notFound()` and exits. Use the `$response`
and `$dbService` helpers present in the file.

Simple static route example (status endpoint):

```php
// after authorizer and helpers are initialized
if ($PATH === '/status' && $METHOD === 'GET') {
    $response->sendJson(['status' => 'ok', 'time' => gmdate('c')], 200, [], 'ALLOW', 'OK', $auth['key'] ?? null);
}
```

Parameterized route (numeric id) with storage access:

```php
if (preg_match('#^/widgets/(\d+)$#', $PATH, $m)) {
    $id = (int)$m[1];
    if ($METHOD === 'GET') {
        try {
            $db = $dbService->load();
        } catch (StorageException $e) {
            Response::getInstance()->notFound(null, 'STORAGE_ERROR');
        }
        $widget = $db['widgets'][(string)$id] ?? null;
        if (!$widget) $response->notFound($auth['key'], 'NOT_FOUND');
        $response->sendJson($widget, 200, [], 'ALLOW', 'OK', $auth['key']);
    }
    // add other methods (PUT/DELETE) as needed
}
```

Notes:
- Use `Response::sendJson()` for responses and `Response::notFound()` to keep error/logging behaviour consistent.
- Read JSON request bodies with `$response->readJsonBody()`.
- Persist via `$dbService->load()` / `$dbService->save()`.

## Example cURL

```bash
# list items
curl -i -H "X-API-Key: demo-key-123" http://localhost:8080/items

# create
curl -i -H "X-API-Key: demo-key-123" -H "Content-Type: application/json" -d '{"name":"Foo"}' http://localhost:8080/items

# read
curl -i -H "X-API-Key: readonly-abc" http://localhost:8080/items/1

# update
curl -i -X PUT -H "X-API-Key: demo-key-123" -H "Content-Type: application/json" -d '{"name":"Foo v2"}' http://localhost:8080/items/1
```

---

If you'd like, I can also tidy `routes.php` into a small router helper or add CI checks (php -l / phpunit).

## Code checks & running tests

Quick commands to validate the code and run the unit test suite locally:

- Install dependencies (if you haven't already):

```bash
composer install
```

- PHP syntax check (single file):

```bash
php -l path/to/file.php
```

- PHP syntax check (all project files, skip vendor):

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 php -l
```

- Run PHPUnit test suite:

```bash
vendor/bin/phpunit --colors=always --testdox
```

Strict test mode
----------------

During development you may want deprecations and notices to fail tests so they are fixed immediately. The test bootstrap (`tests/bootstrap.php`) includes an optional strict error handler that converts deprecations and notices into exceptions. Enable it by setting the `TEST_STRICT_ERRORS` environment variable to `1` when running PHPUnit:

```bash
TEST_STRICT_ERRORS=1 vendor/bin/phpunit --colors=always --testdox
```

When the env var is not set, the test suite will run without converting notices/deprecations to exceptions (the default for CI or casual runs).

- Run a single test or method:

```bash
vendor/bin/phpunit --filter ClassName::methodName
```

Notes:
- If Composer fails due to an extension (for example `ext-dom`), install the corresponding PHP package (on Debian/Ubuntu: `sudo apt install php-xml`) or adjust your environment.
- This project is tested with PHPUnit 10 on PHP 8.1+. If your local PHP version is newer, ensure `composer.json` requires a compatible PHPUnit version.
