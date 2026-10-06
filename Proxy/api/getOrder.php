<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../admin/store.php';
require_once __DIR__ . '/../admin/includes/functions.php';

$tracking = $_GET['trackingNumber'] ?? $_GET['tracking'] ?? '';
if ($tracking === '') {
    http_response_code(400);
    echo json_encode(['error' => 'trackingNumber is required']);
    exit;
}

$order = find_order_by_tracking($tracking);
if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Order not found']);
    exit;
}

$pmData = payment_methods_data_read();
$methods = $pmData['methods'] ?? [];
$methodKey = $order['paymentMethod'] ?? '';
$methodInfo = $methods[$methodKey] ?? [];

$accountNumber = $order['accountNumber'] ?? '';
if ($accountNumber === '') {
    foreach (($methodInfo['accounts'] ?? []) as $acc) {
        if ($acc['enabled'] ?? false) {
            $accountNumber = $acc['number'] ?? '';
            break;
        }
    }
}

echo json_encode([
    'PlatformName' => payment_settings_read()['platformName'] ?? 'VoucherCenter',
    'TrackingNumber' => $order['trackingNumber'],
    'PaymentChannelName' => $order['paymentChannel'] ?? '',
    'CurrencyName' => payment_settings_read()['currency'] ?? 'BDT',
    'Amount' => $order['amount'],
    'RealAmount' => $order['amount'],
    'AccountNumber' => $accountNumber,
    'AccountName' => $methodInfo['name'] ?? $methodKey,
    'OrderStatusName' => $order['status'] ?? 'WaitingConfirm',
    'ExpiredAt' => $order['expiresAt'] ?? null,
    'CreatedAt' => $order['createdAt'] ?? '',
    'PayerAccountNumber' => $order['payerAccount'] ?? null,
    'TransferCode' => $order['trxId'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
