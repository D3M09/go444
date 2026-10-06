<?php
/**
 * Admin front controller. Reached through the proxy's /admin route.
 * Unknown or unauthorised requests are silently sent to the site root.
 */

// Shared settings from the proxy config (created by /setup).
$app = require dirname(__DIR__) . '/config.php';
define('UPSTREAM', rtrim((string) ($app['upstream'] ?? ''), '/'));
define('CACHE_DIR', dirname(__DIR__) . '/cache');
define('CACHE_TTL', max(0, (int) ($app['cache_ttl'] ?? 3600)));
define('BRAND_FROM', (string) ($app['brand_from'] ?? ''));
define('BRAND_TO', (string) ($app['brand_to'] ?? ''));

$cfg = require __DIR__ . '/config.php';
require __DIR__ . '/panel.php';

// Work out the proxy base ('/Proxy' or '') and the part after /admin.
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($reqPath) || $reqPath === '') {
    $reqPath = '/';
}
$marker = '/admin';
$pos = strrpos($reqPath, $marker);
if ($pos === false) {
    $adminDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php')), '/');
    $base = rtrim(str_replace('\\', '/', dirname($adminDir)), '/');
    $sub = '';
} else {
    $base = rtrim(substr($reqPath, 0, $pos), '/');
    $sub = substr($reqPath, $pos + strlen($marker));
}

handleAdmin($base, $sub, $cfg);
