<?php
/**
 * Local intent beacon for withdraw approval queue.
 * The upstream withdraw POST body is RSA/DES encrypted, so PHP cannot read
 * the amount from it. The client shim mirrors plaintext {amount, cardHint}
 * here just before the encrypted submit; the gate later patches the Pending
 * row so the admin sees the amount and the balance hold is accurate.
 *
 * Identity: same fingerprint as Proxy/index.php requestSessionKey()
 * (Cookie + Authorization + Encryption + X-Gateway-Version). The shim
 * forwards Authorization/Merchant from MC_SESSION_INFO so both requests
 * map to the same sessionKey.
 */
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../admin/store.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Merchant, Encryption, X-Gateway-Version');
    http_response_code(204);
    exit;
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$jar = ($_SERVER['HTTP_COOKIE'] ?? '') . "\n"
    . ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')) . "\n"
    . ($_SERVER['HTTP_ENCRYPTION'] ?? '') . "\n"
    . ($_SERVER['HTTP_X_GATEWAY_VERSION'] ?? '');
$sessionKey = trim(str_replace("\n", '', $jar)) === '' ? '' : md5($jar);
if ($sessionKey === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$amount = max(0.0, (float) ($input['amount'] ?? 0));
$cardHint = substr(trim((string) ($input['cardHint'] ?? $input['card'] ?? '')), 0, 64);

$items = withdraw_approvals_read();
$now = time();
// Patch the newest Pending row for this session (created within last 10 min),
// otherwise create a placeholder Pending so the amount is not lost if the
// encrypted submit arrives a moment later.
$target = null;
foreach ($items as &$it) {
    if (($it['sessionKey'] ?? '') !== $sessionKey) {
        continue;
    }
    if (($it['status'] ?? '') !== 'Pending') {
        continue;
    }
    $created = strtotime((string) ($it['createdAt'] ?? ''));
    if ($created !== false && ($now - $created) < 600 && (float) ($it['amount'] ?? 0) <= 0) {
        $target = &$it;
        break;
    }
}
unset($it);
if ($target !== null) {
    if ($amount > 0) {
        $target['amount'] = round($amount, 2);
    }
    if ($cardHint !== '') {
        $target['cardHint'] = $cardHint;
    }
    withdraw_approvals_write($items);
    echo json_encode(['success' => true, 'patched' => true, 'id' => $target['id'] ?? 0]);
    exit;
}

if ($amount <= 0 && $cardHint === '') {
    echo json_encode(['success' => true, 'patched' => false]);
    exit;
}

$id = withdraw_approvals_next_id();
$items[] = [
    'id' => $id,
    'sessionKey' => $sessionKey,
    'sessionHint' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80),
    'amount' => round($amount, 2),
    'cardHint' => $cardHint,
    'payloadHash' => '',
    'status' => 'Pending',
    'createdAt' => date('c'),
    'decidedAt' => null,
    'consumedAt' => null,
    'expiresAt' => $now + 86400,
    'fromIntent' => true,
];
withdraw_approvals_write($items);
echo json_encode(['success' => true, 'patched' => false, 'id' => $id]);
