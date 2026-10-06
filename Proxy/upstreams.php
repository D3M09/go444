<?php
/**
 * Upstream mirror tester / order setter.
 *
 * Times every mirror FROM THIS SERVER and (with save=1) writes the fastest-first
 * order to upstreams.json, which Proxy/index.php reads to decide which mirror to
 * stream from.
 *
 * Why the server must do the measuring: a CDN that is fast from your PC can be
 * slow from the host, and the host's route is the only one the proxy ever uses.
 * Measured from a laptop, 1333bet.ai looked fastest and 888/999 seconds slowest;
 * from the host the ranking can be different entirely.
 *
 * Web:  /Proxy/upstreams.php?key=<admin key>
 *       /Proxy/upstreams.php?key=<admin key>&mirrors=https://a,https://b&save=1
 * CLI:  php Proxy/upstreams.php https://a https://b
 *
 * Nothing happens without the admin key from config.php.
 */

$isCli = PHP_SAPI === 'cli';

// Load config the same way the proxy does, but without booting the proxy itself.
$app = [];
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    $loaded = require $configFile;
    if (is_array($loaded)) {
        $app = $loaded;
    }
}
$configured = rtrim(trim((string) ($app['upstream'] ?? '')), '/');
$adminKey = (string) ($app['admin']['key'] ?? '');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    $given = (string) ($_GET['key'] ?? ($_SERVER['HTTP_X_ADMIN_KEY'] ?? ''));
    if ($adminKey === '' || $given === '' || !hash_equals($adminKey, $given)) {
        http_response_code(403);
        echo "403 Forbidden\n\nThis page measures upstream mirrors from the server.\n";
        echo "Append ?key=<admin key from Proxy/config.php> to use it.\n";
        exit;
    }
}

$mirrorsFile = __DIR__ . '/upstreams.json';
$stateFile = __DIR__ . '/cache/upstream-fail.json';

/** Build the candidate list: explicit ones first, then whatever is already configured. */
$candidates = [];
$add = static function (string $u) use (&$candidates): void {
    $u = rtrim(trim($u), '/');
    if ($u === '' || !preg_match('#^https?://#i', $u)) {
        return;
    }
    if (!in_array($u, $candidates, true)) {
        $candidates[] = $u;
    }
};

$given = (string) ($_GET['mirrors'] ?? '');
if ($given !== '') {
    foreach (explode(',', $given) as $m) {
        $add($m);
    }
}
if ($isCli) {
    for ($i = 1; $i < $argc; $i++) {
        $add($argv[$i]);
    }
}
if (is_file($mirrorsFile)) {
    $raw = @file_get_contents($mirrorsFile);
    $data = $raw !== false ? json_decode($raw, true) : null;
    $order = is_array($data) ? ($data['order'] ?? $data) : null;
    if (is_array($order)) {
        foreach ($order as $m) {
            if (is_string($m)) {
                $add($m);
            }
        }
    }
}
$add($configured);

if (!$candidates) {
    echo "No mirrors to test. Pass them, e.g.\n";
    echo "  php Proxy/upstreams.php https://mirror-1 https://mirror-2\n";
    exit(1);
}

/**
 * Time one mirror: best TTFB of a few samples, plus the response hash so we can
 * tell whether it is really serving the same build as the others.
 */
function probe_mirror(string $base, int $samples = 2): array
{
    $best = null;
    $status = 0;
    $hash = '';
    $size = 0;
    $err = '';
    for ($i = 0; $i < $samples; $i++) {
        $ch = curl_init($base . '/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; mirror-probe)',
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
        $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $e = (string) curl_error($ch);
        if ($body !== false && $st >= 200 && $st < 400) {
            if ($best === null || $ttfb < $best) {
                $best = $ttfb;
            }
            $status = $st;
            $hash = md5((string) $body);
            $size = strlen((string) $body);
        } else {
            $err = $e !== '' ? $e : ('HTTP ' . $st);
        }
    }
    return ['ttfb' => $best, 'status' => $status, 'hash' => $hash, 'size' => $size, 'err' => $err];
}

$results = [];
foreach ($candidates as $base) {
    $results[$base] = probe_mirror($base);
}

// Whatever the first reachable mirror serves is the reference build.
$baseline = '';
foreach ($results as $r) {
    if ($r['ttfb'] !== null && $r['hash'] !== '') {
        $baseline = $r['hash'];
        break;
    }
}

echo "Upstream mirrors, measured from this server (" . date('Y-m-d H:i:s') . ")\n";
echo str_repeat('=', 78) . "\n";
printf("%-42s %9s %7s %10s  %s\n", 'MIRROR', 'TTFB', 'STATUS', 'SIZE', 'VERDICT');
echo str_repeat('-', 78) . "\n";

$usable = [];
$unusable = [];
foreach ($results as $base => $r) {
    if ($r['ttfb'] === null) {
        $verdict = 'UNREACHABLE';
        $unusable[] = $base;
    } elseif ($baseline !== '' && $r['hash'] !== $baseline) {
        $verdict = 'CONTENT DIFFERS';
        $unusable[] = $base;
    } else {
        $verdict = 'ok';
        $usable[$base] = $r['ttfb'];
    }
    printf(
        "%-42s %8s %7s %10s  %s\n",
        $base,
        $r['ttfb'] === null ? '—' : round($r['ttfb'], 3) . 's',
        $r['status'] ?: '—',
        $r['size'] ?: '—',
        $verdict
    );
    if ($verdict === 'UNREACHABLE' && $r['err'] !== '') {
        echo "    ↳ " . $r['err'] . "\n";
    }
}

// Fastest first, and mirrors serving different content or nothing stay at the
// back so a broken one can never displace a working one.
asort($usable);
$order = array_merge(array_keys($usable), $unusable);

echo "\nCurrent order in " . basename($mirrorsFile) . ": ";
if (is_file($mirrorsFile)) {
    $raw = @file_get_contents($mirrorsFile);
    $data = $raw !== false ? json_decode($raw, true) : null;
    $cur = is_array($data) ? ($data['order'] ?? null) : null;
    echo is_array($cur) ? implode(' , ', $cur) : '(unreadable)';
} else {
    echo '(none — proxy is using the configured upstream only)';
}
echo "\n";

if (is_file($stateFile)) {
    $raw = @file_get_contents($stateFile);
    $st = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($st)) {
        $left = (int) ($st['until'] ?? 0) - time();
        echo "Active failover: " . ($st['active'] ?? '?') . " (" . max(0, $left) . "s left, after " . ($st['failed'] ?? '?') . " failed)\n";
    }
}

echo "\nFastest-first order: " . implode(' , ', $order) . "\n";

$doSave = $isCli || (string) ($_GET['save'] ?? '') === '1';
if (!$doSave) {
    echo "\nNot saved. Re-run with  &save=1  to make the proxy use this order.\n";
    exit(0);
}
if (!$usable) {
    echo "\nNothing usable was measured — refusing to overwrite the order.\n";
    exit(1);
}

$payload = json_encode([
    'order'     => $order,
    'updated'   => date('c'),
    'measured'  => 'server',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (@file_put_contents($mirrorsFile, $payload, LOCK_EX) === false) {
    echo "\nCould not write " . $mirrorsFile . " (permissions?).\n";
    exit(1);
}
echo "\nSaved to " . $mirrorsFile . " — the proxy will now stream from " . $order[0] . " first.\n";
