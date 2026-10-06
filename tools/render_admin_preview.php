<?php
/**
 * Dev-only: render every admin section to .freebuff/preview/*.html so the new
 * UI can be checked without logging in. Run: php tools/render_admin_preview.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
$app = require $root . '/Proxy/config.php';

define('UPSTREAM', rtrim((string) ($app['upstream'] ?? ''), '/'));
define('CACHE_DIR', $root . '/Proxy/cache');
define('CACHE_TTL', max(0, (int) ($app['cache_ttl'] ?? 3600)));
define('BRAND_FROM', (string) ($app['brand_from'] ?? ''));
define('BRAND_TO', (string) ($app['brand_to'] ?? ''));

$_SESSION = [
    'px_admin' => true,
    'px_key'   => true,
    'px_user'  => 'admin',
    'px_role'  => 'owner',
    'px_csrf'  => bin2hex(random_bytes(8)),
];

require $root . '/Proxy/admin/panel.php';

$out = $root . '/.freebuff/preview';
if (!is_dir($out)) {
    mkdir($out, 0775, true);
}

$base = '';
$sections = [
    'dashboard'        => fn() => admin_render_dashboard($base, 'Banners saved.'),
    'banners'          => fn() => admin_render_banners($base, ''),
    'marquee'          => fn() => admin_render_marquee($base, ''),
    'games'            => fn() => admin_render_games($base, ''),
    'voucher'          => fn() => admin_render_voucher($base, ''),
    'titles'           => fn() => admin_render_titles($base, ''),
    'logo'             => fn() => admin_render_logo($base, ''),
    'favicon'          => fn() => admin_render_favicon($base, ''),
    'appname'          => fn() => admin_render_appname($base, ''),
    'users'            => fn() => admin_render_users($base, ''),
    'settings'         => fn() => admin_render_settings($base, ''),
    'tools'            => fn() => admin_render_tools($base, ''),
    'payment_settings' => fn() => admin_render_payment_settings($base, ''),
    'withdrawals'      => fn() => admin_render_withdrawals($base, ''),
    'login'            => fn() => admin_render_login($base, ''),
];

foreach ($sections as $name => $fn) {
    ob_start();
    $fn();
    $html = ob_get_clean();
    file_put_contents($out . '/' . $name . '.html', $html);
    printf("%-18s %6d bytes\n", $name, strlen($html));
}
echo "done\n";
