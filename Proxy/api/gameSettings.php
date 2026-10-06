<?php
/**
 * The games configuration the admin panel controls, as the custom mobile
 * frontend consumes it.
 *
 * Deliberately public: it carries nothing secret (what is on/off, which
 * language a game opens in, how many games a page holds). It is not cached, so
 * a change in the panel is live on the next page load.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../admin/store.php';

$g = games_config();

// `types` and `vendors` are full code => bool maps on save, but a code missing
// from the map means "enabled" for the client. Send the maps as stored; the
// client applies the same rule.
$types = [];
foreach (games_type_defaults() as $code => $label) {
    $types[$code] = array_key_exists($code, $g['types']) ? (bool) $g['types'][$code] : true;
}
$vendors = [];
foreach ($g['vendors'] as $code => $on) {
    $code = trim((string) $code);
    if ($code !== '') {
        $vendors[$code] = (bool) $on;
    }
}

$hidden = [];
foreach ($g['hidden'] as $id) {
    $id = trim((string) $id);
    if ($id !== '') {
        $hidden[] = $id;
    }
}

echo json_encode([
    'success' => true,
    'games'   => [
        'enabled'   => (bool) $g['enabled'],
        'language'  => (string) $g['language'],
        'in_app'    => (bool) $g['in_app'],
        'back_url'  => (string) $g['back_url'],
        'page_size' => (int) $g['page_size'],
        'types'     => $types,
        'vendors'   => $vendors,
        'hidden'    => $hidden,
        'updated'   => (string) $g['updated'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
