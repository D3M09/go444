<?php
/**
 * Marks a payment order as consumed (single-view payment page).
 * Called via beacon/fetch when the user closes the payment page (x button)
 * or presses back. Afterwards payment.php renders a "link used" page and
 * the wallet details never appear again for that tracking number.
 * A new payment request creates a new tracking number, unaffected.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../admin/store.php';
require_once __DIR__ . '/../admin/includes/functions.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$tracking = trim((string) ($input['trackingNumber'] ?? $input['tracking'] ?? ''));
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

if (empty($order['consumed'])) {
    update_order($tracking, ['consumed' => true, 'consumedAt' => date('c')]);
}

echo json_encode([
    'success' => true,
    'trackingNumber' => $tracking,
], JSON_PRETTY_PRINT);
