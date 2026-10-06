<?php
/**
 * Admin credentials, taken from the shared proxy config.
 * Direct access is blocked by .htaccess.
 */

$app = require dirname(__DIR__) . '/config.php';
$admin = $app['admin'] ?? [];

return [
    'key'       => (string) ($admin['key'] ?? ''),
    'user'      => (string) ($admin['user'] ?? 'admin'),
    'pass_hash' => (string) ($admin['pass_hash'] ?? ''),
    'cookie'    => (string) ($admin['cookie'] ?? 'px_sid'),
];
