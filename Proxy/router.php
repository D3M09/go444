<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static files directly (skip .php files — they need to be executed)
$uriExt = pathinfo($uri, PATHINFO_EXTENSION);
if ($uri !== '/' && $uriExt !== 'php' && is_file(__DIR__ . $uri)) {
    $mimeTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'html' => 'text/html; charset=utf-8',
    ];
    if (isset($mimeTypes[$uriExt])) {
        header('Content-Type: ' . $mimeTypes[$uriExt]);
    }
    readfile(__DIR__ . $uri);
    return true;
}

// Admin panel - serve locally
if ($uri === '/admin' || strpos($uri, '/admin/') === 0) {
    require __DIR__ . '/admin/index.php';
    return true;
}

// Setup installer - serve locally
if ($uri === '/setup' || $uri === '/setup/' || $uri === '/setup.php') {
    require __DIR__ . '/setup.php';
    return true;
}

require_once __DIR__ . '/admin/store.php';
$voucher = content_load()['voucher'] ?? [];

// VoucherCenter custom only when ON — OFF = normal 404
if ($uri === '/voucherCenter' || $uri === '/voucherCenter/' || strpos($uri, '/voucherCenter/') === 0) {
    if (empty($voucher['enabled'])) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Voucher Center disabled';
        return true;
    }
    $vcFile = __DIR__ . '/..' . $uri;
    if ($uri === '/voucherCenter' || $uri === '/voucherCenter/' || $uri === '/voucherCenter/index.php') {
        require __DIR__ . '/../voucherCenter/index.php';
        return true;
    }
    if (is_file($vcFile)) {
        $ext = pathinfo($uri, PATHINFO_EXTENSION);
        if ($ext === 'php') {
            require $vcFile;
            return true;
        }
        $mimeTypes = [
            'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
            'ico' => 'image/x-icon', 'html' => 'text/html; charset=utf-8',
        ];
        if (isset($mimeTypes[$ext])) header('Content-Type: ' . $mimeTypes[$ext]);
        readfile($vcFile);
        return true;
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '404 Not Found';
    return true;
}

// Payment API endpoints
if ($uri === '/api' || strpos($uri, '/api/') === 0) {
    $apiFile = __DIR__ . $uri;
    if (is_file($apiFile) && pathinfo($apiFile, PATHINFO_EXTENSION) === 'php') {
        require $apiFile;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    return true;
}

// Route everything else through index.php
require __DIR__ . '/index.php';
