<?php
/**
 * Helper functions for the payment/order system.
 */

function generate_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    $hex = bin2hex($data);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
         . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
         . substr($hex, 20, 12);
}

function format_currency(float $amount, string $currency = 'BDT'): string {
    $symbols = ['BDT' => "\xE2\x82\xB9", 'INR' => "\xE2\x82\xB9", 'USD' => '$', 'EUR' => "\xE2\x82\xAC"];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format($amount, 2);
}

function time_ago(string $datetime): string {
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'just now';
}

function payment_status_badge(string $status): string {
    $classes = [
        'WaitingConfirm' => 'badge-warning',
        'Confirmed'      => 'badge-success',
        'Expired'        => 'badge-danger',
        'Failed'         => 'badge-danger',
    ];
    $cls = $classes[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($status) . '</span>';
}
