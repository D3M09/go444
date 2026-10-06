<?php
/**
 * Router for the PHP built-in server when it is started from the project root:
 *
 *     php -S 127.0.0.1:8000 router.php
 *
 * Without a router script the built-in server answers 404 for any missing file
 * that has an extension - which would break every /m/assets/* asset and every
 * backend call (/wps/*, /js/*, /css/* ...) before index.php ever runs. This
 * file mirrors what .htaccess does under Apache: real files are served from
 * disk, everything else goes through Proxy/index.php.
 *
 * (`cd Proxy && php -S 127.0.0.1:8000 router.php`, as in the Procfile, keeps
 * working too - there Proxy/router.php is the entry point.)
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($uri) || $uri === '') {
    $uri = '/';
}

// Refuse path traversal outright; nothing below may have to think about it.
if (strpos($uri, '..') !== false || strpos($uri, '\\') !== false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Bad Request';
    return true;
}

// A real file in the project root (mobile/, images/, img/, css/, Pay.html,
// robots.txt ...): let the dev server deliver it as-is, with its own mime type
// handling. PHP files are never taken this way - they must be executed.
$rootFile = __DIR__ . $uri;
if ($uri !== '/' && is_file($rootFile)
    && strtolower((string) pathinfo($rootFile, PATHINFO_EXTENSION)) !== 'php') {
    return false;
}

// Everything else is the proxy app's job: Proxy/router.php serves the files it
// owns, handles /admin, /setup, /api and /voucherCenter, and hands the rest to
// Proxy/index.php (custom frontend pages + the upstream backend).
require __DIR__ . '/Proxy/router.php';
return true;
