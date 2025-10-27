<?php
declare(strict_types=1);

/**
 * Pure PHP REST API with:
 *  - JSON config for API keys, scopes/routes and rate limits
 *  - Fixed-window per-key rate limiting (fallback to default)
 *  - Logging to logs/api.log (JSON per line) for Fail2Ban
 *  - Masking: unauthorized/forbidden/ratelimited behave as 404
 *
 * Endpoints:
 *   GET  /items
 *   GET  /items/{id}
 *   POST /items
 *   PUT  /items/{id}
 */

// -------- Paths --------
const BASE_DIR    = __DIR__ . '/..';
const CONFIG_FILE = BASE_DIR . '/config/config.json';
const DATA_FILE   = BASE_DIR . '/storage/data.json';
const RL_DIR      = BASE_DIR . '/storage/ratelimit';
const LOG_FILE    = BASE_DIR . '/logs/api.log';

// -------- CORS --------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

// -------- Request globals --------
$METHOD = $_SERVER['REQUEST_METHOD'];
$PATH   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// -------- Config --------
// Load application config and make it available to routes/bootstrap
require_once __DIR__ . '/../config.php';
$CONFIG = load_config();

// Require core modules (they are lightweight and idempotent)
require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/../response.php';
require_once __DIR__ . '/../storage.php';
require_once __DIR__ . '/../access.php';

// Finally dispatch routes (routes.php uses $CONFIG, $METHOD, $PATH variables)
require_once __DIR__ . '/../routes.php';
