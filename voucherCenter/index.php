<?php
/**
 * Serves the voucher-center page with payment-methods data from the
 * admin-managed payment-methods.json and settings.json.
 */

require_once __DIR__ . '/../Proxy/admin/store.php';
require_once __DIR__ . '/../Proxy/admin/includes/functions.php';

$settings = payment_settings_read();
$contentCfg = content_load();
$tmpBrand = trim((string) ($settings['brandName'] ?? ''));
if ($tmpBrand === '') $tmpBrand = trim((string) ($contentCfg['titles']['web_title'] ?? $contentCfg['titles']['app_name'] ?? ''));
$brandTo = $tmpBrand;

$html = @file_get_contents(__DIR__ . '/index.html');
if ($html === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Voucher page not found.';
    exit;
}

$jflags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$jenc = function ($v) use ($jflags) {
    return json_encode($v, $jflags);
};

// Base path this folder is served under. It must NOT come from SCRIPT_NAME:
// the page is reachable both as the canonical /voucherCenter/ route and as the
// proxied source path (/m/voucherCenter), and on the latter the server reports
// the router script, so dirname() yielded '' or '/m'. Every logo URL then
// pointed at /BKASH/*.png (404) and the confirm button went to /payment.php,
// which is the proxied upstream page instead of our per-order payment page.
$voucherCfg = $contentCfg['voucher'] ?? [];
$voucherSrc = '/' . trim((string) (($voucherCfg['path'] ?? '') ?: '/m/voucherCenter'), '/');
$vcBase = '/' . trim((string) (($voucherCfg['redirect_url'] ?? '') ?: '/voucherCenter/'), '/');
if ($vcBase === '/' || $vcBase === '' || stripos($vcBase, 'voucherCenter') === false) {
    // Only paths containing "voucherCenter" are routed to this folder
    // (Proxy/index.php + Proxy/router.php), so anything else would 404.
    $vcBase = '/voucherCenter';
}
$reqPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
if ($reqPath !== '' && strcasecmp($reqPath, $voucherSrc) !== 0) {
    // Subdirectory installs: keep any prefix in front of /voucherCenter.
    $vcPos = stripos($reqPath, '/voucherCenter');
    if ($vcPos !== false) {
        $vcBase = substr($reqPath, 0, $vcPos) . '/voucherCenter';
    }
}

$html = str_replace('/Vcentere/', $vcBase . '/', $html);

$legacyBrands = ['BigAceWin', '1333bk', '1333bet'];
if ($brandTo !== '') {
    foreach (array_unique($legacyBrands) as $from) {
        if ($from === '' || strcasecmp($from, $brandTo) === 0) continue;
        $html = preg_replace('/(?<![\w.\/-])' . preg_quote($from, '/') . '(?!\.[a-z])/i', $brandTo, $html);
    }
}

$tmpPlatform = trim((string) ($contentCfg['titles']['web_title'] ?? $contentCfg['titles']['app_name'] ?? ''));
if ($tmpPlatform === '') $tmpPlatform = trim((string) ($settings['platformName'] ?? 'VoucherCenter'));
$platformName = $tmpPlatform;
$tEsc = htmlspecialchars($platformName, ENT_QUOTES);
$html = preg_replace('/<title>.*?<\/title>/is', '<title>' . $tEsc . '</title>', $html, 1);

$tmpFav = trim((string) ($settings['favicon'] ?? ''));
if ($tmpFav === '') $tmpFav = trim((string) ($contentCfg['favicon']['url'] ?? ''));
$favicon = $tmpFav;
if ($favicon !== '') {
    $fEsc = htmlspecialchars($favicon, ENT_QUOTES);
    $html = preg_replace_callback('/<link\b[^>]*\brel="[^"]*icon[^"]*"[^>]*>/i', function ($m) use ($fEsc) {
        $tag = $m[0];
        if (preg_match('/(?<![\w-])href="[^"]*"/i', $tag)) {
            return preg_replace('/(?<![\w-])href="[^"]*"/i', 'href="' . $fEsc . '"', $tag, 1);
        }
        return rtrim($tag, '> ') . ' href="' . $fEsc . '">';
    }, $html);
}

$pmData = payment_methods_data_read();
$methodsIn = $pmData['methods'] ?? [];

// --- SEO: indexable page, one canonical URL --------------------------------
// The upstream snapshot ships <meta name="robots" content="noindex">, an empty
// <link rel="canonical" href=""> and no og:url. Fix all three so crawlers can
// index this page under a single address instead of split/duplicate variants.
$canonUrl = 'https://bbc99.bet' . ($vcBase !== '' ? $vcBase : '') . '/';
$html = preg_replace('/<meta\s+name="robots"[^>]*>/i', '<meta name="robots" content="index,follow">', $html, 1);
if (stripos($html, 'name="robots"') === false) {
    $html = preg_replace('/<meta\s+charset[^>]*>/i', '$0' . "\n" . '<meta name="robots" content="index,follow">', $html, 1);
}
$html = preg_replace('/<link\s+[^>]*rel="canonical"[^>]*>/i', '<link rel="canonical" href="' . htmlspecialchars($canonUrl, ENT_QUOTES) . '">', $html, 1);
$ogSeo = '<meta property="og:url" content="' . htmlspecialchars($canonUrl, ENT_QUOTES) . '">';
if ($brandTo !== '') {
    $ogSeo .= "\n" . '<meta property="og:site_name" content="' . htmlspecialchars($brandTo, ENT_QUOTES) . '">';
}
// Social image was a relative /m/meta-img.png (resolves to a missing file
// under /voucherCenter/); point it at the absolute proxied copy instead.
// (Fixes both og:image and twitter:image — they share the same value.)
$html = str_replace('/m/meta-img.png?v=35163', 'https://bbc99.bet/res/meta-img.png', $html);
$html = preg_replace('/<\/title>/i', '</title>' . "\n" . $ogSeo, $html, 1);
$ldBrand = $brandTo !== '' ? $brandTo : $platformName;
$ldJson = $jenc([
    '@context'   => 'https://schema.org',
    '@type'      => 'WebSite',
    'name'       => $ldBrand,
    'url'        => $canonUrl,
    'inLanguage' => 'bn',
]);
$html = preg_replace('/<\/body>/i', '<script type="application/ld+json">' . $ldJson . '</script></body>', $html, 1);
$amountsRaw = $pmData['amounts'] ?? [];

$methodImages = [
    'NAGAD'   => $vcBase . '/NAGAD/BN_2_20240312230148421.png',
    'BKASH'   => $vcBase . '/BKASH/BN_2_20240312225413337.png',
    'BKASHSM' => $vcBase . '/BKASHSM/BN_1_20260711012519510.png',
    'NAGADSM' => $vcBase . '/NAGADSM/BN_1_20260711012544019.png',
    'USDT'    => $vcBase . '/USDT/786_CN_1.png',
    'ROCKET'  => $vcBase . '/ROCKET/BN_2_20240312230029166.png',
];

$images = [];
$names = [];
$methodCfg = [];
$channelsByMethod = [];

foreach ($methodsIn as $key => $m) {
    $name = trim((string) ($m['name'] ?? '')) !== '' ? (string) $m['name'] : $key;
    // A logo set in the admin panel wins; otherwise keep the built-in icon.
    $cfgLogo = trim((string) ($m['logo'] ?? ''));
    $image = $cfgLogo !== '' ? $cfgLogo : ($methodImages[$key] ?? '');
    $enabled = (bool) ($m['enabled'] ?? true);
    $color = trim((string) ($m['color'] ?? ''));
    $images[$key] = $image;
    $names[$key] = $name;
    $methodCfg[$key] = [
        'enabled' => $enabled,
        'color'   => $color,
    ];

    $clean = [];
    foreach (($m['accounts'] ?? []) as $acc) {
        if (!($acc['enabled'] ?? true)) continue;
        foreach (($acc['channels'] ?? []) as $ch) {
            if (!($ch['enabled'] ?? true)) continue;
            $label = trim((string) ($ch['name'] ?? ''));
            if ($label === '') continue;
            $min = (int) ($ch['min'] ?? 100);
            $max = (int) ($ch['max'] ?? 30000);
            $exists = false;
            foreach ($clean as $c) {
                if ($c['label'] === $label) { $exists = true; break; }
            }
            if (!$exists) {
                $clean[] = ['label' => $label, 'enabled' => true, 'min' => $min, 'max' => $max];
            }
        }
    }
    if (!$clean) {
        $clean = [['label' => 'Personal', 'enabled' => true, 'min' => 100, 'max' => 30000]];
    }
    $channelsByMethod[$key] = $clean;
}

// Hide payment methods that are disabled in the admin config, server-side, so the
// full method list never flashes before the client script hides it after load.
$html = preg_replace_callback(
    '/<li(\s+class="change-item-animate\s+([A-Za-z0-9_]+)[^"]*")(\s*)>/',
    function ($m) use ($methodCfg) {
        $key = $m[2];
        if (!isset($methodCfg[$key])) return $m[0];
        if (($methodCfg[$key]['enabled'] ?? true)) return $m[0];
        return '<li' . $m[1] . ' style="display:none"' . $m[3] . '>';
    },
    $html
);

$html = preg_replace_callback(
    '/var paymentImages = \{[\s\S]*?\};/',
    function () use ($images, $jenc) {
        return 'var paymentImages = ' . $jenc($images) . ';';
    },
    $html, 1
);

$html = preg_replace_callback(
    '/var names = \{[^}]*\};/',
    function () use ($names, $jenc) {
        return 'var names = ' . $jenc($names) . ';';
    },
    $html, 1
);

$html = preg_replace_callback(
    '/try \{ adminConfig = JSON\.parse\(localStorage\.getItem\(\'voucherPaymentConfig\'\)\) \|\| \{\}; \} catch \(e\) \{ adminConfig = \{\}; \}/',
    function () use ($methodCfg, $jenc) {
        return 'adminConfig = ' . $jenc($methodCfg) . ';';
    },
    $html, 1
);

$rocketName = str_replace(['\\', "'"], ['\\\\', "\\'"], $names['ROCKET'] ?? 'Rocket');
$rocketHtml = '<div class="deposit-icon-bg"><div class="deposit-img-new"><img alt="' . $rocketName . '"></div></div>'
    . '<div class="desc-content"><div class="desc-info"><div class="vcn-list-text"><p>' . $rocketName . '</p></div></div></div>';
$html = preg_replace_callback(
    '/rocket\.innerHTML = \'[^\']*\';/',
    function () use ($rocketHtml) {
        return "rocket.innerHTML = '" . $rocketHtml . "';";
    },
    $html, 1
);

$amounts = [];
foreach ($amountsRaw as $a) {
    $n = (int) preg_replace('/[^\d]/', '', (string) $a);
    if ($n > 0) $amounts[] = $n;
}
if (!$amounts) {
    $amounts = [100, 200, 300, 500, 1000, 3000, 5000, 10000, 30000];
}
$amountItems = '';
foreach ($amounts as $a) {
    $amountItems .= '<div class="fixed-money-item number-mc">' . number_format($a) . '</div>';
}
$html = preg_replace_callback(
    '/(<div class="fixed-money">)[\s\S]*?(<\/div>\s*<div>\s*<div class="inputCon)/',
    function ($m) use ($amountItems) {
        return $m[1] . $amountItems . $m[2];
    },
    $html, 1
);

$firstKey = array_key_first($channelsByMethod) ?: 'BKASH';
$chItems = '';
$firstEnabled = true;
foreach (($channelsByMethod[$firstKey] ?? []) as $ch) {
    $enabled = $ch['enabled'];
    $cls = ($enabled && $firstEnabled) ? 'ck ' : ' ';
    if ($enabled) $firstEnabled = false;
    $style = $enabled ? '' : ' style="display:none"';
    $chItems .= '<li class="' . $cls . '"' . $style . '><span class="method-list-info">'
        . htmlspecialchars($ch['label'], ENT_QUOTES) . '</span></li>';
}
$html = preg_replace_callback(
    '/(<div class="\s*vc-v2-method-list\s*">\s*<ul>)[\s\S]*?(<\/ul>)/',
    function ($m) use ($chItems) {
        return $m[1] . $chItems . $m[2];
    },
    $html, 1
);

$chScript = '<script>(function(){var CH=' . $jenc($channelsByMethod) . ';'
    . 'function keyOf(li){for(var k in CH){if(li&&li.classList&&li.classList.contains(k))return k;}return null;}'
    . 'function tpl(){return document.querySelector("svg.deposit-list-ck");}'
    . 'function mark(li,on){var ex=li.querySelector("svg.deposit-list-ck");if(ex&&ex.parentNode)ex.parentNode.removeChild(ex);if(on){var t=tpl();if(t){var c=t.cloneNode(true);c.style.cssText="position:absolute;right:0;bottom:0;width:.32rem;height:.32rem;display:block;fill:#ec2529;z-index:2;";li.style.position="relative";li.insertBefore(c,li.firstChild);}}}'
    . 'function render(key){var list=CH[key];if(!list||!list.length)return;var ul=document.querySelector(".vc-v2-method-list ul");if(!ul)return;ul.innerHTML="";var first=null;'
    . 'list.forEach(function(ch){var li=document.createElement("li");var sp=document.createElement("span");sp.className="method-list-info";sp.textContent=ch.label||"";li.appendChild(sp);if(ch.enabled===false)li.style.display="none";ul.appendChild(li);'
    . 'if(ch.enabled!==false&&!first)first=li;'
    . 'li.addEventListener("click",function(){var all=ul.querySelectorAll("li");for(var i=0;i<all.length;i++){var on=all[i]===li;all[i].classList.toggle("selected",on);all[i].classList.toggle("ck",on);mark(all[i],on);}window.selectedPaymentChannel=ch.label;});'
    . '});if(first)first.click();}'
    . 'function getActiveMethod(){var sel=document.querySelector("li.change-item-animate.selected");if(sel){var k=keyOf(sel);if(k)return k;}var ck=document.querySelector("li.change-item-animate.ck");if(ck){var k2=keyOf(ck);if(k2)return k2;}var ms=document.querySelectorAll("li.change-item-animate");if(ms.length){var k3=keyOf(ms[0]);if(k3)return k3;}return null;}'
    . 'function boot(){var ms=document.querySelectorAll("li.change-item-animate");for(var i=0;i<ms.length;i++){(function(li){li.addEventListener("click",function(){var k=keyOf(li);if(k)render(k);});})(ms[i]);}var k=getActiveMethod();if(k)render(k);}'
    . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",function(){setTimeout(boot,0);});else setTimeout(boot,0);'
    . '})();</script>';
$html = preg_replace('/<\/body>/i', $chScript . '</body>', $html, 1);

$apiCreateOrder = '/api/createOrder.php';
$paymentUrl = $vcBase . '/payment.php';
$nextJs = '(function(){'
    . 'var m=window.selectedPaymentMethod,a=window.selectedDepositAmount;'
    . 'var c=window.selectedPaymentChannel;'
    . 'if(!c){var chEl=document.querySelector(".vc-v2-method-list li.selected span.method-list-info, .vc-v2-method-list li.ck span.method-list-info");if(chEl)c=chEl.textContent.trim();}'
    . 'if(!c)c="Personal";'
    . 'if(!m||!a){alert("Please select method and amount");return;}'
    . 'var btn=document.querySelector(".vc-v2-submit");'
    . 'if(btn){btn.disabled=true;btn.textContent="Processing...";}'
    . 'fetch("' . $apiCreateOrder . '",{method:"POST",headers:{"Content-Type":"application/json"},'
    . 'body:JSON.stringify({method:m,amount:Number(a.replace(/,/g,"")),channel:c})})'
    . '.then(function(r){return r.json()})'
    . '.then(function(d){if(d.success&&d.trackingNumber){'
    . 'var go=document.getElementById("vcConfirmGo");if(go){go.onclick=function(e){e.preventDefault();window.location.href="' . $paymentUrl . '?tracking="+d.trackingNumber;};}'
    . 'var pop=document.getElementById("vcConfirmPopup");if(pop)pop.classList.add("show");'
    . '}else{alert(d.error||"Failed to create order");if(btn){btn.disabled=false;btn.textContent="\u09AA\u09B0\u09AC\u09B0\u09CD\u09A4\u09C0";}}})'
    . '.catch(function(){alert("Network error. Try again.");if(btn){btn.disabled=false;btn.textContent="\u09AA\u09B0\u09AC\u09B0\u09CD\u09A4\u09C0";}});'
    . '})();';
$html = preg_replace(
    '/window\.location\.href\s*=\s*\x27[^\x27]+\x27\s*\+\s*query\.toString\(\);/',
    $nextJs . ';',
    $html, 1
);

// Fix broken check icon: amount/channel check was at top:5px right:5px with red bg, should be bottom:0 right:0 with correct fill
$html = str_replace(
    "check.style.cssText = 'position:absolute;top:5px;right:5px;width:18px;height:18px;padding:3px;display:block;fill:#fff;color:#fff;background:#e30613;border-radius:50%;z-index:2;'",
    "check.style.cssText = 'position:absolute;right:0;bottom:0;width:.32rem;height:.32rem;display:block;fill:#ec2529;z-index:2;'",
    $html
);
if (strpos($html, 'vcConfirmPopup') === false) {
    $html = str_replace('</body>', '<style>#vcConfirmPopup{display:none;position:fixed;top:0;right:0;bottom:0;left:0;z-index:10000004}#vcConfirmPopup.show{display:block}</style><div class="am-modal am-modal-transparent" id="vcConfirmPopup"><div class="am-modal-mask"></div><div class="am-modal-wrap" role="dialog" aria-modal="true"><div class="am-modal-content"><div class="am-modal-header"><div class="am-modal-title">নিশ্চিতকরণ</div></div><div class="am-modal-body"><div style="zoom:1;overflow:hidden"><div><div>সাফল্য! দয়া করে জমা পৃষ্ঠায় যান</div></div></div></div><div class="am-modal-footer"><div class="am-modal-button-group-v am-modal-button-group-normal" role="group"><a class="am-modal-button" role="button" id="vcConfirmGo">যাও</a></div></div></div></div></div></body>', $html);
}

// Deposit flow: the static snapshot wires the popup "go" button to
// deposit-info.html?tracking=... in a NEW tab. The real destination is the
// per-order payment page in the SAME tab (popup disappears on navigation).
// Absolute URL: a bare 'payment.php?...' resolves against the route this page
// was opened on (/m/voucherCenter -> /m/payment.php), which is the proxied
// upstream page, not our per-order payment page.
$html = str_replace("goButton.href = 'deposit-info.html?tracking='", "goButton.href = '" . $paymentUrl . "?tracking='", $html);
$html = str_replace("goButton.target = '_blank'", "goButton.target = '_self'", $html);

$html = preg_replace('/\sdata-savepage-href="[^"]*"/i', '', $html);
$html = preg_replace('/<meta\s+name="savepage-[^"]*"[^>]*>/i', '', $html);
$html = preg_replace('/<meta\s+name="savepage-from"[^>]*>/i', '', $html);

$tmpLogo = trim((string) ($settings['logo'] ?? ''));
if ($tmpLogo === '') $tmpLogo = trim((string) ($contentCfg['logo']['url'] ?? ''));
$logo = $tmpLogo;
if ($logo !== '') {
    $logoJson = $jenc($logo);
    $logoScript = '<script>(function(){var L=' . $logoJson . ';if(!L)return;'
        . 'function s(){var im=document.getElementsByTagName("img");for(var i=0;i<im.length;i++){'
        . 'var v=(im[i].getAttribute("src")||"")+" "+(im[i].className||"");'
        . 'if(/logo/i.test(v)&&im[i].src!==L){im[i].src=L;}}}'
        . 'if(document.readyState!=="loading")s();else document.addEventListener("DOMContentLoaded",s);'
        . 'setInterval(s,1500);})();</script>';
    $html = preg_replace('/<\/body>/i', $logoScript . '</body>', $html, 1);
}

header('Content-Type: text/html; charset=UTF-8');

// --- Conditional caching ---------------------------------------------------
// The page is rebuilt from index.html + the admin config on every request, so
// its bytes only change when one of those files changes. The old
// "no-cache, must-revalidate" carried no validator, so every visitor had to
// re-download the whole document on every navigation. An ETag turns that into
// a tiny 304, and the short max-age lets repeat views skip the request too.
$validatorFiles = [
    __DIR__ . '/index.html',
    dirname(__DIR__) . '/Proxy/data/settings.json',
    dirname(__DIR__) . '/Proxy/data/payment-methods.json',
    dirname(__DIR__) . '/Proxy/data/content.json',
];
$lastMod = 0;
foreach ($validatorFiles as $vf) {
    $vfMtime = @filemtime($vf);
    if ($vfMtime !== false && $vfMtime > $lastMod) {
        $lastMod = $vfMtime;
    }
}
$etag = '"' . md5($html) . '"';
header('ETag: ' . $etag);
if ($lastMod > 0) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastMod) . ' GMT');
}
header('Cache-Control: private, max-age=60, must-revalidate');

$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$ifModifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
$notModified = false;
if ($ifNoneMatch !== '') {
    $notModified = ($ifNoneMatch === '*' || strpos($ifNoneMatch, $etag) !== false);
} elseif ($ifModifiedSince !== '' && $lastMod > 0) {
    $since = strtotime($ifModifiedSince);
    $notModified = ($since !== false && $since >= $lastMod);
}
if ($notModified) {
    http_response_code(304);
    header('Content-Length: 0');
    exit;
}

echo $html;
