<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../admin/store.php';

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

$statusMap = [
    'WaitingConfirm' => 1,
    'Confirmed' => 2,
    'Expired' => 3,
    'Failed' => 4,
];

echo json_encode([
    'Id' => $order['id'] ?? 0,
    'TrackingNumber' => $order['trackingNumber'],
    'OrderStatusId' => $statusMap[$order['status'] ?? ''] ?? 0,
], JSON_PRETTY_PRINT);
