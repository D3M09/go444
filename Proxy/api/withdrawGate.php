<?php
/**
 * Withdraw approval gate. Included from Proxy/index.php BEFORE fetchUpstream().
 *
 * Usage in index.php:
 *   if (withdrawGateShouldHandle($path)) { withdrawGateHandle($path, $fullPath); }
 * withdrawGateHandle() either exits (queued Pending response) or returns
 * false to let the request fall through to upstream (single-use approval).
 */
require_once __DIR__ . '/../admin/store.php';

function withdrawGateShouldHandle(string $path): bool
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }
    $p = strtolower((string) parse_url($path, PHP_URL_PATH));
    if ($p === '') {
        $p = strtolower($path);
    }
    return (strpos($p, '/wps/v2/transaction/withdraw') !== false
        || strpos($p, '/wps/transaction/withdraw') !== false);
}

function withdrawGateSessionKey(): string
{
    $jar = ($_SERVER['HTTP_COOKIE'] ?? '') . "\n"
        . ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')) . "\n"
        . ($_SERVER['HTTP_ENCRYPTION'] ?? '') . "\n"
        . ($_SERVER['HTTP_X_GATEWAY_VERSION'] ?? '');
    return trim(str_replace("\n", '', $jar)) === '' ? '' : md5($jar);
}

/**
 * @return bool true when the response was already sent (caller must exit),
 *              false when the caller should continue to upstream.
 */
function withdrawGateHandle(string $path): bool
{
    $sessionKey = withdrawGateSessionKey();
    if ($sessionKey === '') {
        return false; // anonymous: let upstream answer 401 as usual
    }

    // Single-use approval? Consume BEFORE forwarding so the next balance
    // fetch already stops subtracting the hold (no double-deduct).
    $usable = withdraw_approval_find_usable($sessionKey);
    if ($usable !== null) {
        withdraw_approval_consume((int) ($usable['id'] ?? 0));
        header('X-Withdraw-Gate: approved-passthrough');
        return false;
    }

    // No approval: queue as Pending and block the upstream debit.
    $raw = (string) @file_get_contents('php://input');
    $payloadHash = $raw !== '' ? md5($raw) : '';
    $now = time();
    $items = withdraw_approvals_read();

    // De-dupe: same encrypted payload retried within 60s -> reuse row.
    foreach ($items as $it) {
        if (($it['sessionKey'] ?? '') !== $sessionKey) {
            continue;
        }
        if (($it['status'] ?? '') !== 'Pending') {
            continue;
        }
        if (($it['payloadHash'] ?? '') !== '' && $it['payloadHash'] === $payloadHash) {
            $created = strtotime((string) ($it['createdAt'] ?? ''));
            if ($created !== false && ($now - $created) < 60) {
                withdrawGateQueuedResponse((int) ($it['id'] ?? 0));
                return true;
            }
        }
    }

    // Attach amount if the intent beacon already created a placeholder row.
    $amount = 0.0;
    $cardHint = '';
    foreach ($items as $it) {
        if (($it['sessionKey'] ?? '') !== $sessionKey || ($it['status'] ?? '') !== 'Pending') {
            continue;
        }
        if (!empty($it['fromIntent']) && (float) ($it['amount'] ?? 0) > 0) {
            $created = strtotime((string) ($it['createdAt'] ?? ''));
            if ($created !== false && ($now - $created) < 600) {
                $amount = (float) $it['amount'];
                $cardHint = (string) ($it['cardHint'] ?? '');
                break;
            }
        }
    }

    $id = withdraw_approvals_next_id();
    $items[] = [
        'id' => $id,
        'sessionKey' => $sessionKey,
        'sessionHint' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80),
        'amount' => round($amount, 2),
        'cardHint' => $cardHint,
        'payloadHash' => $payloadHash,
        'status' => 'Pending',
        'createdAt' => date('c'),
        'decidedAt' => null,
        'consumedAt' => null,
        'expiresAt' => $now + 86400,
        'fromIntent' => false,
    ];
    withdraw_approvals_write($items);
    withdrawGateQueuedResponse($id);
    return true;
}

function withdrawGateQueuedResponse(int $id): void
{
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, must-revalidate');
    header('Vary: Cookie');
    header('X-Proxy: true');
    header('X-Withdraw-Gate: queued');
    $msg = 'Withdraw request received. Waiting for admin approval. Your balance already reflects this hold.';
    echo json_encode([
        'success' => true,
        'queued' => true,
        'approvalStatus' => 'Pending',
        'approvalId' => $id,
        'value' => null,
        'message' => $msg,
        'msg' => $msg,
        'errorMsg' => '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
