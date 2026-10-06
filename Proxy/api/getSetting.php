<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../admin/store.php';

$settings = payment_settings_read();

echo json_encode([
    'Country' => 'Bangladesh',
    'EnableReturnAmount' => false,
    'EnableStoreMode' => false,
    'Id' => 1,
    'IsTest' => $settings['isTest'] ?? true,
    'Language' => $settings['language'] ?? 'bn',
    'PlatformName' => $settings['platformName'] ?? 'VoucherCenter',
    'TimeZone' => $settings['timeZone'] ?? 6,
    'Currency' => $settings['currency'] ?? 'BDT',
    'BrandName' => $settings['brandName'] ?? '',
], JSON_PRETTY_PRINT);
