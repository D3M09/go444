<?php
/**
 * Payment page — loads order by tracking number, injects dynamic data into Pay.html.
 * Orders expire after 10 minutes.
 */

require_once __DIR__ . '/../Proxy/admin/store.php';
require_once __DIR__ . '/../Proxy/admin/includes/functions.php';

$tracking = trim((string) ($_GET['tracking'] ?? ''));
if ($tracking === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '404 Not Found';
    exit;
}

$order = find_order_by_tracking($tracking);
if (!$order) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '404 Not Found';
    exit;
}

$createdAt = strtotime($order['createdAt'] ?? 'now');
$expiresAt = $createdAt + (10 * 60);
$now = time();
$isExpired = ($now >= $expiresAt && $order['status'] === 'WaitingConfirm');

if ($isExpired) {
    update_order($tracking, ['status' => 'Expired']);
    $order['status'] = 'Expired';
}

$method = $order['paymentMethod'];
$amount = $order['amount'];
$channel = $order['paymentChannel'];

$settings = payment_settings_read();
$contentCfg = content_load();
$pmData = payment_methods_data_read();
$methods = $pmData['methods'] ?? [];
$currency = $settings['currency'] ?? 'BDT';
$tmpBrand = trim((string) ($contentCfg['titles']['web_title'] ?? $contentCfg['titles']['app_name'] ?? ''));
if ($tmpBrand === '') $tmpBrand = trim((string) ($settings['brandName'] ?? $settings['platformName'] ?? 'VoucherCenter'));
$brandName = $tmpBrand;
$tmpFav = trim((string) ($settings['favicon'] ?? ''));
if ($tmpFav === '') $tmpFav = trim((string) ($contentCfg['favicon']['url'] ?? ''));
$siteFavicon = $tmpFav !== '' ? $tmpFav : '/images/favicon.ico';

$methodInfo = $methods[$method] ?? null;
if (!$methodInfo || $amount <= 0) {
    header('Location: index.php');
    exit;
}

$accountNumber = $order['accountNumber'] ?? '';
if ($accountNumber === '') {
    $eligible = [];
    foreach (($methodInfo['accounts'] ?? []) as $acc) {
        if (!($acc['enabled'] ?? false)) continue;
        foreach (($acc['channels'] ?? []) as $ch) {
            if (($ch['name'] ?? '') === $channel && ($ch['enabled'] ?? false)) {
                $eligible[] = $acc;
                break;
            }
        }
    }
    if ($eligible) {
        if (count($eligible) === 1) {
            $accountNumber = $eligible[0]['number'] ?? '';
        } else {
            $key = $method . ':' . $channel;
            $next = payment_rotation_peek($key, count($eligible));
            $accountNumber = $eligible[$next]['number'] ?? '';
        }
    } else {
        foreach (($methodInfo['accounts'] ?? []) as $acc) {
            if ($acc['enabled'] ?? false) {
                $accountNumber = $acc['number'] ?? '';
                break;
            }
        }
    }
}
$formattedAmount = number_format($amount);

$methodColors = [
    'BKASH' => '#E2136E', 'BKASHSM' => '#E2136E',
    'NAGAD' => '#F6921E', 'NAGADSM' => '#F6921E',
    'ROCKET' => '#D2122E', 'USDT' => '#26A17B',
];
$methodLabels = [
    'BKASH' => 'bKash', 'BKASHSM' => 'bKash',
    'NAGAD' => 'Nagad', 'NAGADSM' => 'Nagad',
    'ROCKET' => 'Rocket', 'USDT' => 'USDT',
];
$methodBankImages = [
    // Local voucher icons (served directly, always present). The old
    // /images/banks/*.png upstream paths 404, leaving a broken logo.
    'BKASH' => '/voucherCenter/BKASH/BN_2_20240312225413337.png',
    'BKASHSM' => '/voucherCenter/BKASHSM/BN_1_20260711012519510.png',
    'NAGAD' => '/voucherCenter/NAGAD/BN_2_20240312230148421.png',
    'NAGADSM' => '/voucherCenter/NAGADSM/BN_1_20260711012544019.png',
    'ROCKET' => '/voucherCenter/ROCKET/BN_2_20240312230029166.png',
    'USDT' => '/voucherCenter/USDT/786_CN_1.png',
];

$methodColor = $methodColors[$method] ?? '#006644';
$methodLabel = $methodLabels[$method] ?? $method;
// A logo set in the admin panel wins; otherwise keep the built-in icon.
$bankImage = trim((string) ($methodInfo['logo'] ?? ''));
if ($bankImage === '') $bankImage = $methodBankImages[$method] ?? '';
$isSendMoney = (stripos($method, 'SM') !== false || stripos($channel, 'send') !== false);
// Bangla action terms, chosen by channel: cashout vs send-money.
$actionBn = $isSendMoney ? 'সেন্ড মানি' : 'ক্যাশআউট';

$html = @file_get_contents(dirname(__DIR__) . '/Pay.html');
if ($html === false) {
    http_response_code(500);
    echo 'Pay.html not found.';
    exit;
}

$html = preg_replace('/<style id="yt-blacklist-styles">.*?<\/style>/is', '', $html);
$html = preg_replace('/<style id="savepage-cssvariables">.*?<\/style>/is', '', $html);
$html = preg_replace('/<style>\s*<\/style>/is', '', $html);

$html = preg_replace('/<script[^>]*data-savepage-src="[^"]*"[^>]*>.*?<\/script>/is', '', $html);
$html = preg_replace('/<script id="savepage-shadowloader"[^>]*>.*?<\/script>/is', '', $html);

$html = preg_replace_callback(
    '/(<img[^>]*?)data-savepage-currentsrc="([^"]+)"\s+data-savepage-src="([^"]+)"\s+src="data:[^"]*"/i',
    function ($m) { return $m[1] . 'src="' . $m[3] . '"'; },
    $html
);
$html = preg_replace_callback(
    '/(<img[^>]*?)data-savepage-src="([^"]+)"\s+src="data:[^"]*"/i',
    function ($m) { return $m[1] . 'src="' . $m[2] . '"'; },
    $html
);
$html = preg_replace_callback(
    '/(<img[^>]*?)data-savepage-currentsrc="([^"]+)"\s+src="data:[^"]*"/i',
    function ($m) { return $m[1] . 'src="' . $m[2] . '"'; },
    $html
);
$html = preg_replace('/src="data:[^"]*"/', 'src=""', $html);

$html = preg_replace('/<meta\s+name="savepage-[^"]*"[^>]*>/i', '', $html);
$html = preg_replace('/<meta\s+http-equiv="Cache-Control"[^>]*>/i', '', $html);
$html = preg_replace('/<meta\s+http-equiv="Pragma"[^>]*>/i', '', $html);
$html = preg_replace('/<meta\s+http-equiv="Expires"[^>]*>/i', '', $html);
$html = preg_replace('/\sdata-savepage-[a-z]+="[^"]*"/i', '', $html);

$origStyles = '';
$origLinks = '';
if (preg_match('/<head>(.*)<\/head>/is', $html, $headMatch)) {
    $headContent = $headMatch[1];
    preg_match_all('/<style[^>]*>.*?<\/style>/is', $headContent, $styleMatches);
    $origStyles = implode("\n", $styleMatches[0]);
    // Pay.html no longer inlines its CSS bundles; keep the external <link>s.
    preg_match_all('/<link\b[^>]*\brel="stylesheet"[^>]*>/i', $headContent, $linkMatches);
    // Pay.html sits one level above payment.php, so its relative css/ paths
    // need a ../ to resolve from wherever payment.php is served.
    $origLinks = str_replace('href="css/', 'href="../css/', implode("\n", $linkMatches[0]));
}

$headReplace = '<head>'
    . '<meta charset="UTF-8">'
    . '<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">'
    . '<meta name="robots" content="noindex,follow">'
    . '<title>Payment - ' . htmlspecialchars($brandName) . '</title>'
    . '<link rel="icon" href="' . htmlspecialchars($siteFavicon, ENT_QUOTES) . '">'
    . '<link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
    . $origLinks
    . $origStyles
    . '<style>'
    . '.blink-text{animation:blink 1s step-end infinite}'
    . '@keyframes blink{50%{opacity:0}}'
    . '#resultOverlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:10000;background:rgba(0,0,0,.5);align-items:center;justify-content:center}'
    . '#resultOverlay.show{display:flex}'
    . '#resultCard{background:#fff;border-radius:12px;padding:40px 32px;text-align:center;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3)}'
    . '#resultCard .icon{width:64px;height:64px;border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:32px}'
    . '#resultCard .icon.success{background:#d4edda;color:#27ae60}'
    . '#resultCard .icon.error{background:#f8d7da;color:#e74c3c}'
    . '#resultCard h2{font-size:18px;margin:0 0 8px;color:#333}'
    . '#resultCard p{font-size:14px;color:#666;margin:0 0 20px}'
    . '#resultCard a{display:inline-block;padding:10px 24px;background:#006644;color:#fff;text-decoration:none;border-radius:6px;font-weight:600}'
    . '.q-loading,.q-loading__backdrop{display:none!important}'
    . '#q-app{opacity:1!important;visibility:visible!important}'
    . 'body .q-body--loading{overflow:auto!important}'
    . '.q-page{opacity:1!important}'
    . '#expiredOverlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:20000;background:rgba(0,0,0,.7);align-items:center;justify-content:center}'
    . '#expiredOverlay.show{display:flex}'
    . '#expiredCard{background:#fff;border-radius:12px;padding:40px 32px;text-align:center;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3)}'
    . '#expiredCard .icon{width:64px;height:64px;border-radius:50%;margin:0 auto 16px;display:flex;align-items:center;justify-content:center;font-size:32px;background:#f8d7da;color:#e74c3c}'
    . '#expiredCard h2{font-size:18px;margin:0 0 8px;color:#333}'
    . '#expiredCard p{font-size:14px;color:#666;margin:0 0 20px}'
    . '#expiredCard a{display:inline-block;padding:10px 24px;background:#006644;color:#fff;text-decoration:none;border-radius:6px;font-weight:600}'
    . '#countdownBar{position:fixed;top:0;left:0;right:0;height:4px;background:#e0e0e0;z-index:9999}'
    . '#countdownBar .fill{height:100%;background:' . $methodColor . ';transition:width 1s linear}'
    . (($method === 'NAGAD' || $method === 'NAGADSM') ? '.q-banner .q-img.q-img--menu[style*="60px"]{background:#fff!important;border:1px solid #ddd!important;border-radius:50%!important;overflow:hidden!important;padding:4px!important;box-sizing:border-box!important}.q-banner .q-img.q-img--menu[style*="60px"] .q-img__image{border-radius:50%!important}' : '')
    . '</style>'
    . '</head>';

$html = preg_replace('/<head>.*?<\/head>/is', $headReplace, $html, 1);

$html = str_replace('src="/icons/', 'src="/images/icons/', $html);
$html = str_replace('src="/banks/', 'src="/images/banks/', $html);
$html = str_replace('src="/transactions/', 'src="/images/transactions/', $html);
// Cache-bust top icons to bypass stale Cloudflare cache (HTML cached as image)
$html = str_replace('src="/images/icons/pay-service.svg"', 'src="/images/icons/pay-service.svg?v=7cce0e5"', $html);
$html = str_replace('src="/images/icons/pay-page-copy.png"', 'src="/images/icons/pay-page-copy.png?v=7cce0e5"', $html);

$html = preg_replace('/background:\s*rgb\(0,\s*102,\s*68\)/', 'background: ' . $methodColor, $html);
$html = preg_replace('/background:\s*rgb\(242,\s*79,\s*65\)/', 'background: ' . $methodColor, $html);

$html = preg_replace('/<b class="col-12" style="font-size:\s*20px;">BDT\s+[\d,]+<\/b>/',
    '<b class="col-12" style="font-size:20px;">' . htmlspecialchars($currency) . ' ' . $formattedAmount . '</b>', $html, 1);

$html = preg_replace('/value="01877668758"/', 'value="' . htmlspecialchars($accountNumber) . '"', $html, 1);

// Confirmation sentence: rewrite BEFORE the generic NAGAD->label swap below,
// while the 'NAGAD deposit ...' source text is still intact. \S+ covers the
// wallet word in any Bengali spelling variant (য় vs য+়).
$html = preg_replace('/NAGAD\s+deposit\s+\S+\s+নাম্বারে[^<]*/u',
    htmlspecialchars($methodLabel) . ' ' . $actionBn . ' ওয়ালেট নাম্বারে ' . $actionBn . ' করছেন। এই নাম্বারের অন্য কোন ওয়ালেট থেকে ' . $actionBn . ' করলে সেই টাকা পাওয়ার কোন সম্ভাবনা নাই', $html);

$html = str_replace('NAGAD Deposit', htmlspecialchars($methodLabel) . ' ' . $actionBn, $html);
$html = str_replace('NAGAD', htmlspecialchars($methodLabel), $html);

$html = preg_replace('/এই\s+' . preg_quote(htmlspecialchars($methodLabel)) . '\s+নাম্বারে[^<]*/',
    'এই ' . htmlspecialchars($methodLabel) . ' নাম্বারে শুধুমাত্র ' . $actionBn . ' গ্রহণ করা হয়', $html);

if ($bankImage) {
    $html = preg_replace('/\/images\/banks\/[A-Za-z]+\.png/', $bankImage, $html);
}

$html = preg_replace('/আপনি যদি টাকার পরিমাণ পরিবর্তন করেন \(BDT\s+[\d,]+\)/',
    'আপনি যদি টাকার পরিমাণ পরিবর্তন করেন (' . htmlspecialchars($currency) . ' ' . $formattedAmount . ')', $html);

$html = str_replace('কম বা বেশি ক্যাশআউট করবেন না', 'কম বা বেশি ' . $actionBn . ' করবেন না', $html);
$html = str_replace('ক্যাশআউটের TrxID নাম্বারটি লিখুন',
    ($isSendMoney ? 'সেন্ড মানির TrxID নাম্বারটি লিখুন' : 'ক্যাশআউটের TrxID নাম্বারটি লিখুন'), $html);

$html = preg_replace(
    '/(<button\s+class="q-btn\s+q-btn-item\s+non-selectable\s+no-outline\s+q-btn--outline\s+q-btn--rectangle\s+q-btn--square\s+text-black\s+q-btn--actionable\s+q-focusable\s+q-hoverable\s+q-btn--no-uppercase\s+q-btn--square"\s+tabindex="0"\s+type="button"\s+style=")([^"]*)(">)/',
    '$1$2" id="submitBtn" onclick="submitTransaction()"$3',
    $html, 1
);
$html = str_replace('onclick="submitTransaction()""', 'onclick="submitTransaction()"', $html);

$html = preg_replace(
    '/(<div class="q-img q-img--menu cursor-pointer")/',
    '$1 onclick="copyWallet()"',
    $html, 1
);

$expiresAtMs = $expiresAt * 1000;

$expiredClass = $isExpired ? ' show' : '';
$existingTrxId = (string) ($order['trxId'] ?? '');

// Single canonical "back" target: absolute path so it resolves the same
// wherever payment.php was opened from (root, /m/..., subdirectory).
$depositUrl = '/voucherCenter/deposit-info.html?tracking=' . urlencode($tracking);

$injection = ''
    . '<div id="countdownBar"><div class="fill" id="countdownFill"></div></div>'
    . '<a id="payCloseBtn" href="' . htmlspecialchars($depositUrl, ENT_QUOTES) . '" aria-label="Close payment page" title="Close"'
    . ' style="position:fixed;top:12px;right:12px;z-index:9999;width:36px;height:36px;border-radius:50%;background:rgba(0,0,0,.55);color:#fff;font-size:22px;line-height:36px;text-align:center;text-decoration:none;font-family:Arial,sans-serif;">&times;</a>'
    . '<style>'
    . '.trx-success{border-color:#27ae60!important}'
    . '#trxConfirmOverlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:10000005;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:16px}'
    . '#trxConfirmOverlay.show{display:flex}'
    . '#trxEditWrap{display:none}'
    . '</style>'
    . '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>'
    . '<script>'
    . 'var trackingNumber=' . json_encode($tracking) . ';'
    . 'var apiBase=' . json_encode('/api') . ';'
    . 'var expiresAtMs=' . $expiresAtMs . ';'
    . 'var serverNow=' . ($now * 1000) . ';'
    . 'var trxSubmitted=false;'
    . 'var existingTrxId=' . json_encode($existingTrxId) . ';'
    . 'var pendingTrx="";'
    . 'var depositUrl=' . json_encode($depositUrl) . ';'

    // The payment page must be seen at most once: leave it with
    // location.replace() so it is removed from history and Back from
    // deposit-info can never restore it. Also guard bfcache restores
    // (Back/Forward re-visits) by swapping in deposit-info immediately.
    . '(function(){'
    . 'var cb=document.getElementById("payCloseBtn");'
    . 'if(cb)cb.addEventListener("click",function(e){'
    . 'if(e.metaKey||e.ctrlKey||e.shiftKey||e.altKey||e.button!==0)return;'
    . 'e.preventDefault();location.replace(depositUrl);});'
    . 'window.addEventListener("pageshow",function(e){if(e.persisted)location.replace(depositUrl);});'
    . '})();'

    . 'var trxLabelMsg="";function trxInput(){var el=document.querySelector(".trx-input")||document.querySelector(".input-red input")||document.querySelector(".input-green input");if(el&&!el.classList.contains("trx-input"))el.classList.add("trx-input");return el;}'
    . 'function syncTrxField(){var tx=trxInput();if(!tx)return;var has=tx.value.trim()!=="";var hideHint=has||document.activeElement===tx;var f=tx.closest(".q-field");if(f){var lb=f.querySelector(".q-field__label");if(lb){if(!trxLabelMsg)trxLabelMsg=lb.textContent;lb.style.display=hideHint?"none":"";}f.classList.toggle("input-red",!has);f.classList.toggle("input-green",has);var c=f.querySelector(".q-field__control");if(c)c.classList.toggle("text-red",!has);}tx.setAttribute("aria-label",has?"TrxID":(trxLabelMsg||"TrxID"));}'
    . 'function submitBtnEl(){return document.getElementById("submitBtn");}'
    . 'function editWrapEl(){return document.getElementById("trxEditWrap");}'
    . 'function showEditBtn(){var w=editWrapEl();if(w)w.style.display="flex";}'
    . 'function hideEditBtn(){var w=editWrapEl();if(w)w.style.display="none";}'
    . 'function markSubmitted(){var tx=trxInput(),sb=submitBtnEl();trxSubmitted=true;if(tx){tx.readOnly=true;tx.classList.add("trx-success");}if(sb)sb.style.display="none";showEditBtn();syncTrxField();}'
    . 'function markEditable(){var tx=trxInput(),sb=submitBtnEl();trxSubmitted=false;hideEditBtn();if(tx){tx.readOnly=false;tx.classList.remove("trx-success");}if(sb){sb.style.display="";sb.disabled=false;}syncTrxField();}'
    . 'function openTrxConfirm(v){pendingTrx=v;var t=document.getElementById("trxConfirmTrx");if(t)t.textContent=" "+v+" ";var o=document.getElementById("trxConfirmOverlay");if(o)o.classList.add("show");}'
    . 'function closeTrxConfirm(){var o=document.getElementById("trxConfirmOverlay");if(o)o.classList.remove("show");pendingTrx="";}'

    . 'function tick(){'
    . 'var now=Date.now(),remaining=expiresAtMs-now;'
    . 'if(remaining<=0){'
    . 'var eo=document.getElementById("expiredOverlay");if(eo)eo.classList.add("show");'
    . 'var tx=trxInput();if(tx)tx.disabled=true;'
    . 'var sb=document.getElementById("submitBtn");if(sb)sb.disabled=true;'
    . 'var cf=document.getElementById("countdownFill");if(cf)cf.style.width="0%";'
    . 'hideEditBtn();closeTrxConfirm();'
    . 'return;}'
    . 'var total=10*60*1000,pct=Math.max(0,(remaining/total)*100);'
    . 'var cfl=document.getElementById("countdownFill");if(cfl)cfl.style.width=pct+"%";'
    . 'var m=Math.floor(remaining/60000),s=Math.floor((remaining%60000)/1000);'
    . 'var cd=document.getElementById("countdownText");'
    . 'if(cd)cd.textContent=m+":"+(s<10?"0":"")+" "+s;'
    . 'setTimeout(tick,1000);}'
    . 'tick();'

    . 'function copyWallet(){var i=document.querySelector("input[readonly]");if(!i)return;var v=i.value;if(!v)return;if(navigator.clipboard){navigator.clipboard.writeText(v)}else{var t=document.createElement("textarea");t.value=v;document.body.appendChild(t);t.select();document.execCommand("copy");document.body.removeChild(t)}var el=document.querySelector(".q-img.q-img--menu");if(el){var tip=document.createElement("span");tip.textContent="Copied!";tip.style.cssText="position:absolute;top:-30px;left:50%;transform:translateX(-50%);background:#006644;color:#fff;padding:4px 12px;border-radius:4px;font-size:12px;white-space:nowrap;z-index:9999;pointer-events:none";el.style.position="relative";el.appendChild(tip);setTimeout(function(){tip.remove()},1500)}}'

    . 'function submitTransaction(){'
    . 'var sb=submitBtnEl();if(sb&&(sb.disabled||sb.style.display==="none"))return;'
    . 'var tx=trxInput();if(!tx)return;var v=tx.value.trim();'
    . 'if(!v){tx.focus();return;}'
    . 'openTrxConfirm(v);}'

    . 'function doSubmitTransaction(){'
    . 'var sb=submitBtnEl();var tx=trxInput();'
    . 'var v=tx?tx.value.trim():pendingTrx;if(pendingTrx)v=pendingTrx;if(!v)return;'
    . 'closeTrxConfirm();'
    . 'if(sb)sb.disabled=true;'
    . 'fetch(apiBase+"/submitTransaction.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({trackingNumber:trackingNumber,trxId:v})})'
    . '.then(function(r){return r.json()})'
    . '.then(function(d){if(d.success){'
    . 'if(tx&&tx.value.trim()!==v)tx.value=v;'
    . 'markSubmitted();'
    . '}else{if(sb)sb.disabled=false;alert((d&&(d.error||d.message))||"Submit failed. Please try again.");}})'
    . '.catch(function(){if(sb)sb.disabled=false;alert("Network error. Please check connection and try again.");})}'

    . 'function requestEditTrx(){'
    . 'var tx=trxInput();if(!tx)return;'
    . 'var hasValue=trxSubmitted||tx.value.trim()!=="";'
    . 'if(!hasValue){markEditable();tx.focus();return;}'
    . 'var proceed=function(){var sb=submitBtnEl();hideEditBtn();tx.readOnly=false;tx.value="";tx.focus();tx.classList.remove("trx-success");if(sb){sb.style.display="";sb.disabled=false;}trxSubmitted=false;syncTrxField();};'
    . 'if(window.Swal&&Swal.fire){Swal.fire({icon:"warning",title:"Order has already bind Transaction ID, are you sure you want to change?",showCancelButton:true,confirmButtonText:"Yes",cancelButtonText:"No"}).then(function(r){if(r&&r.isConfirmed)proceed();});}'
    . 'else{if(window.confirm("Order has already bind Transaction ID, are you sure you want to change?"))proceed();}}'
    . 'function editTrx(){requestEditTrx();}'

    . 'function ensureEditButton(){'
    . 'var tx=trxInput();if(!tx||document.getElementById("trxEditWrap"))return;'
    . 'var control=tx.closest(".q-field__control");'
    . 'var wrap=document.createElement("div");wrap.className="q-field__append q-field__marginal row no-wrap items-center";wrap.id="trxEditWrap";wrap.style.display="none";'
    . 'var btn=document.createElement("button");btn.className="q-btn q-btn-item non-selectable no-outline q-btn--outline q-btn--rectangle q-btn--rounded text-green q-btn--actionable q-focusable q-hoverable q-btn--no-uppercase";btn.type="button";btn.id="editTrxBtn";'
    . 'var content=document.createElement("span");content.className="q-btn__content text-center col items-center q-anchor--skip justify-center row no-wrap text-no-wrap";'
    . 'var icon=document.createElement("i");icon.className="q-icon on-left mdi mdi-pencil";icon.setAttribute("aria-hidden","true");icon.setAttribute("role","img");icon.textContent="";'
    . 'var label=document.createElement("span");label.className="block";label.textContent="";'
    . 'content.appendChild(icon);content.appendChild(label);btn.appendChild(content);'
    . 'var helper=document.createElement("span");helper.className="q-focus-helper";btn.appendChild(helper);'
    . 'label.textContent="\\u09AA\\u09B0\\u09BF\\u09AC\\u09B0\\u09CD\\u09A4\\u09A8 \\u0995\\u09B0\\u09C1\\u09A8";'
    . 'icon.textContent="\\u2710";'
    . 'btn.addEventListener("click",function(e){e.preventDefault();requestEditTrx();});'
    . 'wrap.appendChild(btn);'
    . 'if(control)control.appendChild(wrap);else if(tx.parentNode)tx.parentNode.appendChild(wrap);}'
    . 'function bootTrx(){'
    . 'ensureEditButton();'
    . 'var tx=trxInput();'
    . 'if(tx){tx.addEventListener("input",syncTrxField);tx.addEventListener("focus",syncTrxField);tx.addEventListener("blur",syncTrxField);tx.addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();submitTransaction();}});}'
    . 'var cc=document.getElementById("trxConfirmCancel");if(cc)cc.addEventListener("click",function(){closeTrxConfirm();var sb=submitBtnEl();if(sb)sb.disabled=false;});'
    . 'var ok=document.getElementById("trxConfirmOk");if(ok)ok.addEventListener("click",function(){doSubmitTransaction();});'
    . 'var ov=document.getElementById("trxConfirmOverlay");if(ov)ov.addEventListener("click",function(e){if(e.target===ov){closeTrxConfirm();var sb2=submitBtnEl();if(sb2)sb2.disabled=false;}});'
    . 'if(existingTrxId&&tx){tx.value=existingTrxId;if(Date.now()<expiresAtMs){markSubmitted();}else{tx.readOnly=true;tx.disabled=true;hideEditBtn();}}if(tx)syncTrxField();}'
    . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",bootTrx);else bootTrx();'
    . '</script>'

    . '<div id="trxConfirmOverlay"><div class="q-card column no-wrap flex-center" style="width: 600px; max-width: 90vw;"><div class="q-card__section q-card__section--vert text-center q-pa-lg" style="font-size: 16px;"><span class="text-grey-8">This order can only be submitted once, please confirm your Transaction ID:</span><span class="text-red" id="trxConfirmTrx"></span><span class="text-grey-8">is correct!</span></div><div class="q-card__actions justify-center q-card__actions--horiz row q-pa-lg"><button class="q-btn q-btn-item non-selectable no-outline q-btn--standard q-btn--rectangle q-btn--rounded q-btn--actionable q-focusable q-hoverable q-btn--no-uppercase" id="trxConfirmCancel" style="padding: 4px 32px; min-width: 0px; min-height: 0px; background: rgb(204, 204, 204); color: rgb(48, 48, 48);" tabindex="0" type="button"><span class="q-focus-helper"></span><span class="q-btn__content text-center col items-center q-anchor--skip justify-center row no-wrap text-no-wrap"><span class="block">Cancel</span></span></button><button class="q-btn q-btn-item non-selectable no-outline q-btn--standard q-btn--rectangle q-btn--rounded q-btn--actionable q-focusable q-hoverable q-btn--no-uppercase text-white" id="trxConfirmOk" style="padding: 4px 32px; min-width: 0px; min-height: 0px; background: linear-gradient(rgb(0, 102, 68), rgb(0, 102, 68));" tabindex="0" type="button"><span class="q-focus-helper"></span><span class="q-btn__content text-center col items-center q-anchor--skip justify-center row no-wrap text-no-wrap"><span class="block">Confirm</span></span></button></div></div></div>'

    . '<div id="expiredOverlay"' . $expiredClass . '><div id="expiredCard">'
    . '<div class="icon">&#x2717;</div>'
    . '<h2>Payment Expired</h2>'
    . '<p>This payment session has expired. Please go back and create a new order.</p>'
    . '<a href="/voucherCenter/">Back to VoucherCenter</a>'
    . '</div></div>';

$html = str_replace('</body>', $injection . '</body>', $html);

if ($isExpired) {
    $html = str_replace('id="submitBtn"', 'id="submitBtn" disabled', $html);
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
echo $html;
