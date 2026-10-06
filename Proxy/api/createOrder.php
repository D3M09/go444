<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'POST required']); exit; }

require_once __DIR__ . '/../admin/store.php';
require_once __DIR__ . '/../admin/includes/functions.php';

$input = json_decode(file_get_contents('php://input'), true);
$method = trim(strtoupper((string) ($input['method'] ?? '')));
$amount = (int) ($input['amount'] ?? 0);
$channel = trim((string) ($input['channel'] ?? ''));
$accountNumber = trim((string) ($input['accountNumber'] ?? ''));

if ($method === '' || $amount <= 0 || $channel === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing method, amount, or channel']);
    exit;
}

$pmData = payment_methods_data_read();
$methods = $pmData['methods'] ?? [];
if (!isset($methods[$method])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payment method']);
    exit;
}

$methodInfo = $methods[$method];
if (!($methodInfo['enabled'] ?? false)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Method is disabled']);
    exit;
}

$chMin = 100;
$chMax = 30000;
$chFound = false;
$matchedAccount = null;
$eligible = [];

foreach (($methodInfo['accounts'] ?? []) as $acc) {
    if (!($acc['enabled'] ?? false)) continue;
    if ($accountNumber !== '' && ($acc['number'] ?? '') !== $accountNumber) continue;
    foreach (($acc['channels'] ?? []) as $ch) {
        if (($ch['name'] ?? '') === $channel && ($ch['enabled'] ?? false)) {
            $eligible[] = ['acc' => $acc, 'ch' => $ch];
            break;
        }
    }
    if ($accountNumber !== '' && $eligible) {
        break;
    }
}

if ($eligible) {
    if ($accountNumber !== '') {
        $matchedAccount = $eligible[0]['acc'];
        $chMin = $eligible[0]['ch']['min'] ?? 100;
        $chMax = $eligible[0]['ch']['max'] ?? 30000;
        $chFound = true;
    } elseif (count($eligible) === 1) {
        $matchedAccount = $eligible[0]['acc'];
        $chMin = $eligible[0]['ch']['min'] ?? 100;
        $chMax = $eligible[0]['ch']['max'] ?? 30000;
        $chFound = true;
    } else {
        $key = $method . ':' . $channel;
        $next = payment_rotation_next($key, count($eligible));
        $matchedAccount = $eligible[$next]['acc'];
        $chMin = $eligible[$next]['ch']['min'] ?? 100;
        $chMax = $eligible[$next]['ch']['max'] ?? 30000;
        $chFound = true;
    }
}

if (!$chFound) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Channel "' . $channel . '" not available for this method']);
    exit;
}

if ($amount < $chMin || $amount > $chMax) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Amount out of range (' . $chMin . ' - ' . $chMax . ')']);
    exit;
}

$tracking = generate_uuid();
$now = date('c');

$order = [
    'id'              => orders_next_id(),
    'trackingNumber'  => $tracking,
    'paymentMethod'   => $method,
    'paymentChannel'  => $channel,
    'accountNumber'   => $matchedAccount['number'] ?? '',
    'amount'          => $amount,
    'payerAccount'    => null,
    'trxId'           => null,
    'status'          => 'WaitingConfirm',
    'createdAt'       => $now,
    'expiresAt'       => date('c', strtotime($now . ' +10 minutes')),
    'confirmedAt'     => null,
];

$orders = orders_read();
$orders[] = $order;
orders_write($orders);

echo json_encode([
    'success'        => true,
    'trackingNumber' => $tracking,
    'expiresAt'      => $order['expiresAt'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
