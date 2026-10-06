<?php
/**
 * First-run setup installer.
 *
 * Open /setup once to configure the proxy. After config.php exists the
 * installer is locked: it only opens with the admin key (?key=...) or an
 * authenticated admin session, otherwise it silently redirects home.
 */

$configFile = __DIR__ . '/config.php';
$installed = is_file($configFile);
$existing = $installed ? (require $configFile) : [];
$existingAdmin = $existing['admin'] ?? [];

// ---- helpers -------------------------------------------------------------

function setup_base(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/setup.php')), '/');
}

function setup_home(): string
{
    $b = setup_base();
    return $b === '' ? '/' : $b . '/';
}

function setup_url(string $suffix = ''): string
{
    return setup_base() . '/setup' . $suffix;
}

function setup_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function setup_session_start(array $existingAdmin): void
{
    session_name($existingAdmin['cookie'] ?? 'px_sid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function setup_test_upstream(string $url, string $ua): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => $ua !== '' ? $ua : 'Mozilla/5.0',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,*/*'],
        CURLOPT_HTTP_VERSION   => defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_2_0,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $time   = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $err    = curl_error($ch);
    return [$status, $ct, $time, $err];
}

function setup_render(string $title, string $body): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    echo '<!doctype html><html><head><meta charset="utf-8">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . setup_h($title) . '</title><style>'
        . '*{box-sizing:border-box}body{margin:0;font:15px/1.55 system-ui,Segoe UI,Arial;background:#0f1115;color:#e6e8ee}'
        . 'a{color:#7cc4ff}.wrap{max-width:760px;margin:0 auto;padding:30px}'
        . 'h1{font-size:20px;margin:0 0 6px}.sub{color:#9aa3b2;font-size:13px;margin-bottom:22px}'
        . 'fieldset{border:1px solid #23262e;border-radius:12px;background:#161a21;padding:18px;margin:0 0 18px}'
        . 'legend{padding:0 8px;color:#9aa3b2;font-size:13px}'
        . 'label{display:block;font-size:13px;color:#9aa3b2;margin:14px 0 6px}'
        . 'input,select{width:100%;padding:11px 12px;border-radius:8px;border:1px solid #2b3038;background:#0f1115;color:#e6e8ee}'
        . 'button{margin-top:8px;padding:11px 18px;border:0;border-radius:8px;background:#2f6df6;color:#fff;font-weight:600;cursor:pointer}'
        . 'button.ghost{background:#1b1f27;border:1px solid #2b3038;color:#e6e8ee}'
        . '.err{background:#3a1d22;border:1px solid #6b2a34;color:#ffb3bd;padding:10px 12px;border-radius:8px;font-size:13px;margin:0 0 16px}'
        . '.ok{background:#16301f;border:1px solid #2b6b41;color:#9ff0bd;padding:10px 12px;border-radius:8px;font-size:13px;margin:0 0 16px}'
        . '.warn{background:#332a12;border:1px solid #6b5a2a;color:#ffe0a3;padding:10px 12px;border-radius:8px;font-size:13px;margin:0 0 16px}'
        . 'code{background:#0b0d11;border:1px solid #23262e;border-radius:6px;padding:1px 6px;font-size:13px}'
        . '.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}'
        . '</style></head><body><div class="wrap">' . $body . '</div></body></html>';
}

function setup_export(array $cfg): string
{
    return "<?php\n"
        . "/**\n"
        . " * Proxy configuration. Generated/updated by the setup installer (/setup).\n"
        . " * Delete this file to force the installer to run again.\n"
        . " */\n\n"
        . 'return ' . var_export($cfg, true) . ";\n";
}

// ---- access control ------------------------------------------------------

setup_session_start($existingAdmin);
$keyOk = !$installed || !empty($_SESSION['px_admin']);
if ($installed) {
    $given = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
    if ($given !== '' && $given === (string) ($existingAdmin['key'] ?? '')) {
        $keyOk = true;
        $_SESSION['px_key'] = true;
    }
    if (!empty($_SESSION['px_key'])) {
        $keyOk = true;
    }
}
if (!$keyOk) {
    header('Location: ' . setup_home(), true, 302);
    exit;
}

if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['setup_csrf'];

// ---- defaults ------------------------------------------------------------

$defaults = [
    'upstream'   => $existing['upstream'] ?? 'https://www.go444.io',
    'brand_from' => $existing['brand_from'] ?? 'go444',
    'brand_to'   => $existing['brand_to'] ?? 'LottoBet',
    'cache_ttl'  => $existing['cache_ttl'] ?? 3600,
    'user_agent' => $existing['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
    'admin_user' => $existingAdmin['user'] ?? 'admin',
    'admin_key'  => $existingAdmin['key'] ?? 'demon',
    'cookie'     => $existingAdmin['cookie'] ?? 'px_sid',
    'disable_affiliate_redirect' => $existing['disable_affiliate_redirect'] ?? true,
];

// Extra keys that must survive a re-run of the installer.
$preserveKeys = ['referral_code', 'referral_affiliate_code', 'reg_mobile_pattern', 'reg_username_pattern'];

$errors = [];
$notice = '';

// ---- POST ----------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isset($_POST['csrf']) || !hash_equals($csrf, (string) $_POST['csrf'])) {
        $errors[] = 'Session expired. Reload the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? 'save');
        $upstream = rtrim(trim((string) ($_POST['upstream'] ?? '')), '/');
        $brandFrom = trim((string) ($_POST['brand_from'] ?? ''));
        $brandTo = trim((string) ($_POST['brand_to'] ?? ''));
        $ttl = (int) ($_POST['cache_ttl'] ?? 0);
        $ua = trim((string) ($_POST['user_agent'] ?? ''));
        $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
        $adminKey = trim((string) ($_POST['admin_key'] ?? ''));
        $cookie = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['cookie'] ?? 'px_sid'));
        $pass = (string) ($_POST['admin_pass'] ?? '');
        $disableRedirect = !empty($_POST['disable_affiliate_redirect']);

        if ($action === 'test') {
            if (!filter_var($upstream, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $upstream)) {
                $errors[] = 'Enter a valid http(s) upstream URL first.';
            } else {
                [$st, $ct, $ms, $err] = setup_test_upstream($upstream, $ua !== '' ? $ua : $defaults['user_agent']);
                if ($err !== '') {
                    $errors[] = 'Connection failed: ' . $err;
                } else {
                    $notice = 'Upstream responded HTTP ' . $st . ' in ' . $ms . ' ms (' . ($ct ?: 'unknown type') . ').';
                }
                $defaults = array_merge($defaults, compact('upstream', 'brandFrom', 'brandTo', 'ttl', 'ua', 'adminUser', 'adminKey', 'cookie') + [
                    'brand_from' => $brandFrom, 'brand_to' => $brandTo, 'cache_ttl' => $ttl,
                    'user_agent' => $ua, 'admin_user' => $adminUser, 'admin_key' => $adminKey,
                    'disable_affiliate_redirect' => $disableRedirect,
                ]);
            }
        } else {
            if (!filter_var($upstream, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $upstream)) {
                $errors[] = 'Upstream URL must be a valid http(s) URL.';
            }
            if ($brandFrom !== '' && $brandTo === '') {
                $errors[] = 'Brand replacement needs both a "from" and a "to" value.';
            }
            if ($ttl < 0 || $ttl > 2592000) {
                $errors[] = 'Cache TTL must be between 0 and 2592000 seconds.';
            }
            if ($ua === '') {
                $errors[] = 'User agent is required.';
            }
            if ($adminUser === '') {
                $errors[] = 'Admin username is required.';
            }
            if (strlen($adminKey) < 4) {
                $errors[] = 'Admin key must be at least 4 characters (use a long random string).';
            }
            if ($cookie === '') {
                $cookie = 'px_sid';
            }
            if ($pass === '' && empty($existingAdmin['pass_hash'])) {
                $errors[] = 'Admin password is required.';
            }
            if ($pass !== '' && strlen($pass) < 8) {
                $errors[] = 'Admin password must be at least 8 characters.';
            }

            if (!$errors) {
                $passHash = $pass !== '' ? password_hash($pass, PASSWORD_DEFAULT) : (string) $existingAdmin['pass_hash'];
                $cfg = [
                    'upstream'   => $upstream,
                    'brand_from' => $brandFrom,
                    'brand_to'   => $brandTo,
                    'cache_ttl'  => $ttl,
                    'user_agent' => $ua,
                    'disable_affiliate_redirect' => $disableRedirect,
                    'admin' => [
                        'key'       => $adminKey,
                        'user'      => $adminUser,
                        'pass_hash' => $passHash,
                        'cookie'    => $cookie,
                    ],
                ];
                // Keep any extra settings (referral code, registration rules).
                foreach ($preserveKeys as $pk) {
                    if (array_key_exists($pk, $existing)) {
                        $cfg[$pk] = $existing[$pk];
                    }
                }
                $cacheDir = __DIR__ . '/cache';
                if (!is_dir($cacheDir)) {
                    @mkdir($cacheDir, 0755, true);
                }
                $tmp = $configFile . '.tmp';
                if (@file_put_contents($tmp, setup_export($cfg)) === false || !@rename($tmp, $configFile)) {
                    @unlink($tmp);
                    $errors[] = 'Could not write config.php. Check folder permissions.';
                } else {
                    // From now on the installer is locked; keep the current session unlocked.
                    $installed = true;
                    $existingAdmin = $cfg['admin'];
                    $_SESSION['px_key'] = true;
                    $home = setup_home();
                    $adminUrl = setup_h($home . 'admin/login?key=' . rawurlencode($adminKey));
                    $body = '<h1>Setup complete</h1><div class="sub">Configuration saved to <code>config.php</code>.</div>'
                        . '<div class="ok">Your proxy is now live. Keep the admin key and password safe.</div>'
                        . '<div class="row"><a class="badge" href="' . setup_h($home) . '" style="text-decoration:none;padding:10px 16px;border:1px solid #2b3038;border-radius:8px">Open site</a> '
                        . '<a href="' . $adminUrl . '" style="text-decoration:none;padding:10px 16px;border-radius:8px;background:#2f6df6;color:#fff;font-weight:600">Open admin</a></div>';
                    setup_render('Setup complete', $body);
                    exit;
                }
            }
            $defaults = array_merge($defaults, [
                'upstream' => $upstream, 'brand_from' => $brandFrom, 'brand_to' => $brandTo,
                'cache_ttl' => $ttl, 'user_agent' => $ua, 'admin_user' => $adminUser,
                'admin_key' => $adminKey, 'cookie' => $cookie,
                'disable_affiliate_redirect' => $disableRedirect,
            ]);
        }
    }
}

// ---- form ----------------------------------------------------------------

$errBox = '';
foreach ($errors as $e) {
    $errBox .= '<div class="err">' . setup_h($e) . '</div>';
}
$okBox = $notice !== '' ? '<div class="ok">' . setup_h($notice) . '</div>' : '';
$lockNote = $installed
    ? '<div class="warn">Installer unlocked with your admin key. Saving will update the running configuration.</div>'
    : '<div class="warn">First-time setup. Choose a strong admin key and password before going live.</div>';

$body = '<h1>' . ($installed ? 'Proxy setup' : 'Welcome to proxy setup') . '</h1>'
    . '<div class="sub">Detected base path: <code>' . setup_h(setup_base() === '' ? '/' : setup_base()) . '</code></div>'
    . $errBox . $okBox . $lockNote
    . '<form method="post" action="' . setup_h(setup_url() . ($installed ? '?key=' . rawurlencode((string) $existingAdmin['key']) : '')) . '">'
    . '<input type="hidden" name="csrf" value="' . setup_h($csrf) . '">'
    . '<fieldset><legend>Target site</legend>'
    . '<label>Upstream URL (the site being proxied)</label>'
    . '<input name="upstream" value="' . setup_h($defaults['upstream']) . '" placeholder="https://example.com">'
    . '<label>User agent</label>'
    . '<input name="user_agent" value="' . setup_h($defaults['user_agent']) . '">'
    . '<label>Cache TTL (seconds)</label>'
    . '<input name="cache_ttl" type="number" min="0" max="2592000" value="' . setup_h((string) $defaults['cache_ttl']) . '">'
    . '<label class="row" style="margin-top:14px"><input type="checkbox" name="disable_affiliate_redirect" value="1" ' . (!empty($defaults['disable_affiliate_redirect']) ? 'checked' : '') . ' style="width:auto"> <span>Disable affiliate sub-domain redirect (recommended &mdash; keeps a sub-domain such as <code>lottogamez.gamer.free</code> serving this proxy)</span></label>'
    . '</fieldset>'
    . '<fieldset><legend>Brand replacement</legend>'
    . '<label>Replace this text</label>'
    . '<input name="brand_from" value="' . setup_h($defaults['brand_from']) . '" placeholder="oldbrand">'
    . '<label>With this text</label>'
    . '<input name="brand_to" value="' . setup_h($defaults['brand_to']) . '" placeholder="NewBrand">'
    . '</fieldset>'
    . '<fieldset><legend>Admin panel</legend>'
    . '<label>Admin key (URL secret, e.g. ?key=...)</label>'
    . '<input name="admin_key" value="' . setup_h($defaults['admin_key']) . '">'
    . '<label>Admin username</label>'
    . '<input name="admin_user" value="' . setup_h($defaults['admin_user']) . '">'
    . '<label>Admin password' . ($installed ? ' (leave blank to keep current)' : '') . '</label>'
    . '<input name="admin_pass" type="password" autocomplete="new-password">'
    . '<label>Session cookie name</label>'
    . '<input name="cookie" value="' . setup_h($defaults['cookie']) . '">'
    . '</fieldset>'
    . '<div class="row">'
    . '<button type="submit" name="action" value="save">Save configuration</button>'
    . '<button type="submit" name="action" value="test" class="ghost">Test upstream</button>'
    . '</div></form>';

setup_render('Proxy setup', $body);
