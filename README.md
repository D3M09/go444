# go444 Proxy — BBC99

Reverse proxy + custom frontend + local payment/withdraw system for the `go444` whitelabel (upstream `https://www.go444.io`, rebranded to `BBC99`).

- **Stack:** PHP `>=8.1`, no composer dependencies (`composer.json` is metadata only), JSON-file storage, Apache / nginx / PHP built-in server.
- **Live entry:** `Proxy/index.php` (root `index.php` and `router.php` are thin stubs that delegate to it).

```
Browser ──► router.php ──► Proxy/router.php ──► Proxy/index.php ──► upstream go444.io
                  │                │                     │
                  │                ├─ /admin/* ─► Proxy/admin/index.php → panel.php
                  │                ├─ /setup   ─► Proxy/setup.php
                  │                ├─ /api/*   ─► Proxy/api/*.php (local)
                  │                ├─ /voucherCenter/* ─► voucherCenter/*.php
                  │                └─ /m/assets/* ─► mobile/assets/* + mobile/*.html
                  └─ real files (css/, images/, Pay.html, robots.txt…) served as-is
```

## Quick start

```bash
# dev (always with router — otherwise /m/assets/*, /wps/*, /js/* 404)
php -S 127.0.0.1:8000 router.php

# production-style (Procfile)
cd Proxy && php -S 0.0.0.0:$PORT router.php
```

1. Open `/setup` once → set upstream, brand `go444 → BBC99`, cache TTL, UA, admin user/key/password. Writes `Proxy/config.php`; afterwards `/setup` is locked (needs `?key=<admin key>` or admin session).
2. Open `/admin` → configure content, payments, games, withdraw approvals.
3. Optional mirrors: `/Proxy/upstreams.php?key=<admin key>&mirrors=https://a,https://b&save=1` or `php Proxy/upstreams.php https://a https://b`.

Apache works via root `.htaccess` + `Proxy/.htaccess`; `nginx.conf` and `Procfile` are included. `CGIPassAuth On` / `Authorization` forwarding is required (upstream auth is header-based).

## Repository map

| Path | What it is |
|---|---|
| `router.php` | Dev-server router: blocks `..`, serves real non-PHP files, else `Proxy/router.php`. |
| `index.php` | Stub → `Proxy/index.php`. |
| `Proxy/index.php` (~3090 lines) | Front controller: custom pages, cache, mirror failover, withdraw gate, fetch + rewrite + inject pipeline. |
| `Proxy/router.php` | Static files, `/admin`, `/setup`, `/voucherCenter`, `/api/*`, else `index.php`. |
| `Proxy/config.php` | Generated config (upstream, brand, TTL, UA, referral, reg patterns, admin). |
| `Proxy/setup.php` | First-run installer + re-config (CSRF, upstream test, atomic write). |
| `Proxy/upstreams.php` + `upstreams.json` | Server-side mirror speed test, fastest-first order. |
| `Proxy/admin/{index,panel,config,store}.php` + `includes/functions.php` | Admin panel (`/admin`), JSON storage helpers. |
| `Proxy/api/*.php` | Local JSON API (deposit orders, settings, games, withdraw intent). |
| `Proxy/data/*.json` | Runtime DB: `content, settings, payment-methods, orders, payment-rotation, withdraw-approvals, users, games-vendors`. Denied from web. |
| `Proxy/cache/` | Disk cache of upstream assets + `upstream-health.json`, `upstream-fail.json`. |
| `mobile/{index,login,register,game,404}.html` + `assets/{api,app,home,config}.js` | Custom frontend (home/auth/game shell). Served raw — no rewriting. |
| `voucherCenter/{index,payment}.php` + static assets | Local deposit UI (order create → per-order pay page). |
| `Pay.html`, `css/`, `images/`, `img/`, `mobile/` | Static assets for voucher/pay pages. |
| `.htaccess`, `nginx.conf`, `Procfile`, `router.php` | Deploy / routing glue. |
| `tools/`, `problem-to-fix.md`, `plan-for-member-home.md` | Audit scripts, E2E notes, member-home plan. |

## Proxy (`Proxy/index.php`) — request pipeline

1. **Base/prefix:** derives mount base from `REQUEST_URI` (never `SCRIPT_NAME`), 301s `/Proxy/* → /*`. Rejects `..` / `\`.
2. **Custom pages** (`mobile/`): `/, /index(.html|.php), /home, /m, /m/, /m/index(.html), /m/home → index.html`; `/m/login → login.html`; `/m/register → register.html`; `/m/game → game.html` (in-app game frame with Back button). `/m/account → /m/member/home`, `/m/deposit → /m/voucherCenter` (302 legacy redirects).
3. **Local assets:** `/m/assets/* → mobile/assets/*` (ETag, `no-cache,must-revalidate`), `/favicon.ico`, self-unregistering `/sw.js` + `/m/sw.js` stub (kills upstream offline shell).
4. **Local routes:** `/admin`, `/setup`, `/voucherCenter` (only when `voucher.enabled`, else 404), configurable voucher path, any URL containing `voucherCenter` when enabled, block `*.apk|*.ipa`.
5. **Cache:** `CACHE_TTL>0` + `isCacheable()` + not `/` or `/index.php`. Fresh → `serveFile` (HIT). Expired + degraded upstream → serve stale (1-in-20 revalidates). Single-flight fill lock (`CACHE_FILL_WAIT_MS=2500`). `CACHE_TTL=0` + `/res/*` → 302 straight to upstream CDN.
6. **Withdraw gate** (`api/withdrawGate.php`): `POST */wps/v2/transaction/withdraw` or `*/wps/transaction/withdraw` → queue `Pending` unless single-use `Approved` exists (then consume + passthrough).
7. **Fetch:** `fetchUpstream()` over `upstreamMirrors()` (from `upstreams.json` + configured upstream), HTTP/2, IPv4, shared curl handle, 3s connect / 6s total (3s when degraded), 10s failover budget, `demoteUpstream()` for 120s (`UPSTREAM_FAIL_TTL`), degraded window 30s on slow (>2000ms) / fail. Forwards method/body + `Content-Type, Authorization, Merchant, Device, Language, X-Gateway-Version, Encryption, X-Digest, X-RSA, Cookie` + real browser UA. Retries upstream 5xx once (no retry on timeouts). `domainRoute 400 request_param_err` → minimal success; `WPSCORE_checkIfAppDomain` → `{"success":true,"value":null}` (anti-clone trap).
8. **Transform:** strip `console.log("brand",brand)`; brand replace (HTML/JSON, domains/auth keys skipped); invite-domain → current host; content/banners/marquee/apk/register-rules/withdraw-balance+report/script-patches; HTML: `rewriteAndCache` (`/res/*` → local path, lazy-cached on browser request) + combined shims + app-icon + SEO + boot shim (no splash/promo popups on `/m*`).
9. **Respond:** correct `Cache-Control` — auth traffic / errors / authed JSON → `private,no-store`; HTML → `no-cache`; static → `public,max-age=86400,immutable`. `X-Proxy: true`, `Connection: close`. `finishRequestEarly()` then write cache + GC + unlock.

Health/congestion: `UPSTREAM_SLOW_MS=2000`, `UPSTREAM_DEGRADED_TTL=30`, `DEGRADED_REVALIDATE_1_IN=20`, asset-HTML mismatch → 404 (no cache poisoning).

Brand rewrite: `brand_from` (`go444`) + hardcoded variants (`lottogamez, 1333bk, 1333bet, BigAceWin, lotto`) → `brand_to`, URL/domain-aware, longest-first. Protected JSON keys: `domainList, domainRoute, domainName, projectId, authDomain, apiKey, appId, messagingSenderId, storageBucket, measurementId, firebaseConfig`. Support/chat URLs → `https://support.bbc99.bet`.

## Local API (`/api/*` → `Proxy/api/*.php`)

All CORS-open (`Access-Control-Allow-Origin: *`), JSON. Routed by `Proxy/router.php`: existing `*.php` under `/api/` executes, else `404 {"error":"Not found"}`.

### Deposit / orders (manual MFS flow: bKash / Nagad / Rocket / USDT)

| Method + path | Body / query | Success | Errors |
|---|---|---|---|
| `POST /api/createOrder.php` | `{method, amount, channel, accountNumber?}` | `200 {success, trackingNumber(uuid), expiresAt(+10m)}` — status `WaitingConfirm`, round-robin wallet when several accounts share method+channel (`payment_rotation_next`) | `405` non-POST, `400` missing/invalid/disabled method, unknown channel, amount out of `[min,max]` (default 100–30000) |
| `GET /api/getOrder.php?trackingNumber=` | (alias `tracking=`) | order view: `PlatformName, TrackingNumber, PaymentChannelName, CurrencyName(BDT), Amount, RealAmount, AccountNumber, AccountName, OrderStatusName, ExpiredAt, CreatedAt, PayerAccountNumber, TransferCode` | `400` no tracking, `404` not found |
| `GET /api/getOrderStatus.php?trackingNumber=` | | `{Id, TrackingNumber, OrderStatusId}` — `WaitingConfirm=1, Confirmed=2, Expired=3, Failed=4` | same as above |
| `POST /api/submitTransaction.php` | `{trackingNumber, payerAccount?, trxId|transferCode?}` (JSON or form) | `{success, message:"Transaction confirmed", trackingNumber, status:"Confirmed"}` — `WaitingConfirm→Confirmed+confirmedAt`; `Confirmed` resubmit only patches fields | `405`, `400`, `404`, `{error:"Order is not pending"}` when Expired/Failed |
| `POST /api/consumeOrder.php` | `{trackingNumber}` | `{success, trackingNumber}` — single-view: sets `consumed(+consumedAt)`; `payment.php` then shows “link used” | `405/400/404` |

Order shape (`orders.json`): `{id(nextId from 1001), trackingNumber, paymentMethod, paymentChannel, accountNumber, amount, payerAccount, trxId, status, createdAt, expiresAt, confirmedAt, consumed?, consumedAt?}`.

### Settings / games (public)

| Path | Output |
|---|---|
| `GET /api/getSetting.php` | `{Country:"Bangladesh", EnableReturnAmount:false, EnableStoreMode:false, Id:1, IsTest, Language(bn), PlatformName, TimeZone(6), Currency(BDT), BrandName}` from `settings.json` |
| `GET /api/gameSettings.php` | `{success, games:{enabled, language(EN), in_app, back_url(/m/home), page_size(24), types{RNG,LIVE,PVP,SPORTS,FISH,ELOTT,ESPORTS}, vendors{code:bool}, hidden[], updated}}` — `no-store`, missing code = enabled. Consumed by `PXAPI.loadSettings()` |

### Withdraw approval (local gate, upstream bodies are encrypted)

- `POST /api/withdrawIntent.php` — plaintext beacon `{amount, cardHint|card}` before the encrypted submit. Same session fingerprint as gate (`md5(Cookie+Authorization+Encryption+X-Gateway-Version)`). Patches newest `Pending` (≤10m, amount≤0) or creates `fromIntent:true` placeholder. `401` when anonymous.
- `Proxy/api/withdrawGate.php` — **not a route**, included pre-fetch. `withdrawGateShouldHandle()`: `POST` + path contains `/wps/v2/transaction/withdraw` or `/wps/transaction/withdraw`. `withdrawGateHandle()`: anonymous → passthrough; usable `Approved`+unconsumed+unexpired → `withdraw_approval_consume()` + `X-Withdraw-Gate: approved-passthrough` + fall through to upstream; else de-dupe same `payloadHash` ≤60s → `X-Withdraw-Gate: queued` + `200 {success, queued:true, approvalStatus:"Pending", approvalId, value:null, message…}` and queue row `{id, sessionKey, sessionHint(UA80), amount(from intent), cardHint, payloadHash, status:Pending, createdAt, decidedAt:null, consumedAt:null, expiresAt:+24h, fromIntent:false}`. Balance/report shims subtract active holds so UI reflects the hold immediately.

## Upstream API (proxied `/wps/*`, via `mobile/assets/api.js` → `PXAPI`)

Same-origin calls; proxy forwards to upstream. Merchant header `go44bdtf5` (`config.js` `PX_MERCHANT`, must match upstream), `Device: web`, `Language`, `Authorization: <token>` (from `sessionStorage token|MC_SESSION_INFO|login`), `X-Real-UA` (b64 UA). Encrypted endpoints load `/js/encrypt.js` → `reRsaV2(payload)` → body `{value: DES}` + `Encryption: RSA, X-Digest: DES, X-RSA: rsaKey`.

| `PXAPI` | Upstream | Notes |
|---|---|---|
| `login/loginDeviceId` | `POST /wps/session/login` (enc) | `{username(=mobile alias), password, type:"username", loginDeviceId(SHELL_deviceId UUID)}`; persists `value.token` |
| `logout` | `POST /wps/session/logout` | clears token even on failure |
| `register/registerMobile` | `PUT /wps/member/register`, `PUT /wps/member/register/mobile` (enc) | `{mobile, password, smsCode, inviteCode?}` + deviceId |
| `registerAuto` | `PUT /wps/member/register/autoUsername` | |
| `registerSetting/countryCode/sendSms/sendLoginSms/captcha/captchaGeetest` | `GET /wps/system/setting/register`, `GET /wps/system/country`, `POST /wps/verification/sms/{register,noLogin}`, `GET /wps/captcha[?t=]` (+geetest) | captcha `no-store`, `captchaImage()` → data-URL |
| `memberInfo/balance` | `GET /wps/member/info`, `GET /wps/member/info/funds/consolidated` | |
| `gameList/hotGames/gameVendors/gameMenus/gameTypes/winnerBoard` | `GET /wps/relay/GCSGAME_gameList`, `POST …_hotGamesV2`, `GET …_newGameVendor`, `GET …_getVassGameType` (×2), `GET …_getRankList` | list is GET-query relay |
| `launchGame` (+`gameUrl()`) | `GET /wps/game/launchGame` | defaults `launchMode:"GLS", accountType:1(real-money), language:<panel>`; URL from `value.content.game_url` or `value.url|gameUrl|link|launchUrl` |
| `announcements` | `GET /wps/relay/CCSFE_getListAnnouncements` | |

Stale `401` token → dropped + single guest retry (public feeds still 200).

## Admin panel (`/admin` → `Proxy/admin/index.php` → `panel.php`)

Auth: session `px_sid` (configurable), `password_hash` users in `users.json` (seeded from setup), `?key=<admin key>` unlock, CSRF per form, unknown/unauth → site root. Sections: Dashboard (quick actions, cache size, purge), Banners, Marquee (multi-item `{text,link}` + style/speed), Voucher (`enabled, path /m/voucherCenter, redirect_url /voucherCenter, logo, amounts, methods`), Titles (`web/mobile/app_name`), Logo/Favicon (upload/URL → `/images/brand/`, ≤3MB), App name, Users (add/delete/reset), Settings (platform/brand/currency/timezone/language/isTest), Tools (purge cache), Payment methods (per-method `enabled,color,logo`, wallets `{number,name,enabled}`, channels `{name,enabled,min,max}`, amounts list), Games (`enabled, language, in_app, back_url, page_size 4–120, types, vendors` live from relay ~84 codes, `hidden[]`, missing = enabled), Withdraw approvals (Pending/Approved/Rejected, consume tracking), Content/branding. All writes atomic (`*.tmp` + rename, `flock` for rotation).

## Storage (`Proxy/data/*.json`)

`content.json` (banners/marquee/titles/logo/favicon/voucher/games), `settings.json` (platform/brand/currency…), `payment-methods.json` (`methods{…accounts[]…channels[]}+amounts[]`), `orders.json` (`{orders[], nextId}`), `payment-rotation.json` (`"METHOD:channel"→idx`), `withdraw-approvals.json` (`{items[], nextId}`), `users.json`, `games-vendors.json`. Helpers in `store.php`: `content_load/save`, `orders_*, payment_*, withdraw_approvals_*, users_*, games_*`, `config_load/save`, brand-asset + support-URL utils.

## VoucherCenter / deposit UI

`/voucherCenter/` (when enabled) → `voucherCenter/index.php` builds page from `index.html` + `payment-methods.json` + `settings.json` + `content.json`: brand/title/favicon/SEO-canonical, method logos, amounts, channels, per-method enable (server-hidden), `POST /api/createOrder.php` → confirm popup → `payment.php?tracking=` (from `Pay.html`, per-order wallet/amount/color, 10-min countdown, trxId submit → `/api/submitTransaction.php`, single-view via `/api/consumeOrder.php`, expired overlay). ETag/`Last-Modified`, `private,max-age=60`.

## Frontend (`mobile/`)

`index.html` (home: categories/provider filter/search/games/winners/banners), `login.html` + `register.html` (referral prefilled from `__PX_REFERRAL_CODE__`), `game.html` (frames launched game + Back/reload, single relay call), `404.html` (reserved). `assets/api.js` (`PXAPI` above), `app.js` (auth forms), `home.js` (grid via `PXAPI` only), `config.js` (`PX_MERCHANT='go44bdtf5'`, `PX_GAME_GROUPS`), `app.css/home.css`, `img/{bkash,nagad,rocket,favicon,logo}.png`. Served at `/m/assets/*` with revalidation; bump `?v=` after CSS changes (old immutable window).

## Config reference (`Proxy/config.php`)

`upstream` (e.g. `https://www.go444.io`), `brand_from` (`go444`), `brand_to` (`BBC99`), `cache_ttl` (s, `0`=off), `user_agent`, `referral_code` (`ggp0537`, posted by register form only — never seeded into storage), `referral_affiliate_code`, `reg_mobile_pattern` (`^01[3-9]\d{8}$`), `reg_username_pattern` (`^[A-Za-z][A-Za-z0-9]*$`), `disable_affiliate_redirect` (default true), `admin{key,user,pass_hash,cookie}`. Constants derived in `index.php`: `UPSTREAM, CACHE_DIR/TTL, UPSTREAM_FAIL_TTL=120, FAILOVER_BUDGET=10, SLOW_MS=2000, DEGRADED_TTL=30, REVALIDATE_1_IN=20, FILL_WAIT_MS=2500, USER_AGENT, BRAND_*, REFERRAL_*, REG_*_PATTERN, DISABLE_AFFILIATE_REDIRECT, BRAND_SKIP_KEYS`.

## Deploy

- Apache: root `.htaccess` (`/admin→Proxy/admin`, `/setup→Proxy/setup`, `/api/*→Proxy/api/*`, real files as-is, rest → `Proxy/index.php`) + `CGIPassAuth`. Subfolder installs supported via base detection.
- nginx: see `nginx.conf` (`/admin`, `/setup`, `/VoucherCenter`, `/ → index.php`, `*.php → php-fpm`).
- Procfile/hosts: `web: cd Proxy && php -S 0.0.0.0:$PORT router.php`. PHP `curl`, `json`, `mbstring` required. `Proxy/cache` + `Proxy/data` writable. Never commit `config.php` secrets / `data/*.json` PII — rotate admin key + password after clone.

## Troubleshooting

| Symptom | Check |
|---|---|
| `/setup` redirects home | `config.php` exists → append `?key=<admin key>` |
| Login `400 request_body_required` | proxy must forward `X-Digest`/`X-RSA` (fixed in `fetchFromUpstream`) |
| Games `#727 system busy` | `launchMode` missing → `PXAPI.launchGame` defaults `GLS` |
| Games in Chinese | `language` missing → defaults to panel language (`EN`) |
| Blank proxied pages | backslash `$base` / bad shim — derive base from `REQUEST_URI`, validate |
| Bounced to foreign domain | `reg_info` referral seeding — must scrub, not write |
| Stale captcha / 401 member feeds | captcha `no-store`; stale `Authorization` dropped + guest retry |
| Dead mirror stalls all traffic | `upstreams.php?key=…&save=1`, check `cache/upstream-fail.json`, health window |
| Voucher 404 | `content.json → voucher.enabled` must be true; path must contain `voucherCenter` |
