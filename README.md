# go444 Proxy — BBC99

> Reverse proxy, custom mobile frontend, and local deposit / withdraw system for the `go444` whitelabel — rebranded and operated as **BBC99**.

![PHP >= 8.1](https://img.shields.io/badge/PHP-%3E%3D%208.1-777BB4?logo=php&logoColor=white)
![No dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)
![Storage](https://img.shields.io/badge/storage-JSON%20files-blue)
![Upstream](https://img.shields.io/badge/upstream-www.go444.io-orange)
![Brand](https://img.shields.io/badge/brand-BBC99-red)

**What this is:** a self-hosted PHP front door that sits in front of `https://www.go444.io`, serves its own home / login / register / game-shell pages, proxies everything else with brand rewriting and disk caching, and adds two things the upstream does not give you — a manual mobile-money deposit desk (bKash, Nagad, Rocket, USDT) and an admin-approved withdraw gate.

**What this is not:** a fork of the upstream casino software. Game logic, wallets, sessions, and member data stay on the upstream backend (`/wps/*`). This repo owns the edge: routing, presentation, branding, caching, payments UX, and moderation.

- **Stack:** PHP `>= 8.1`, zero composer packages, JSON-file storage, Apache / nginx / PHP built-in server.
- **Live entry point:** `Proxy/index.php`. Root `index.php` and `router.php` are thin stubs that delegate to it.

---

## Table of contents

1. [Features](#1-features)
2. [How it works](#2-how-it-works)
3. [Repository structure](#3-repository-structure)
4. [Getting started](#4-getting-started)
5. [Configuration reference](#5-configuration-reference)
6. [Routing reference](#6-routing-reference)
7. [Local API reference (`/api/*`)](#7-local-api-reference-apistar)
8. [Upstream API via `PXAPI` (`/wps/*`)](#8-upstream-api-via-pxapi-wpsstar)
9. [Proxy engine deep dive](#9-proxy-engine-deep-dive)
10. [Deposit flow (VoucherCenter)](#10-deposit-flow-vouchercenter)
11. [Withdraw approval flow](#11-withdraw-approval-flow)
12. [Admin panel guide](#12-admin-panel-guide)
13. [Frontend guide (`mobile/`)](#13-frontend-guide-mobile)
14. [Brand and MFS logos](#14-brand-and-mfs-logos)
15. [Deployment](#15-deployment)
16. [Security](#16-security)
17. [Storage and data files](#17-storage-and-data-files)
18. [Tools and maintenance](#18-tools-and-maintenance)
19. [Troubleshooting](#19-troubleshooting)
20. [FAQ](#20-faq)

---

## 1. Features

**Edge proxy**

- Full reverse proxy with disk cache, mirror failover, health-aware degraded mode, and single-flight cache fills — survives upstream wobbles without taking the process pool down.
- Brand rewriting (`go444` plus legacy variants → `BBC99`) that is URL/domain-aware and skips auth, CDN, and Firebase keys.
- Invite/share domain repair, customer-service URL consolidation (`support.bbc99.bet`), APK/IPA blocking, affiliate-redirect kill switch, anti-clone trap neutralizers.

**Custom frontend**

- Own home, login, register, and in-app game shell (`mobile/`). No upstream HTML/CSS/JS is referenced; the design survives upstream rebuilds.
- `PXAPI` client (`mobile/assets/api.js`) mirrors upstream endpoints, handles `Merchant` context, session tokens, device UUIDs, RSA/DES encryption handshake, and stale-token recovery.

**Local payments**

- Deposit desk (`voucherCenter/`): method → channel → amount → per-order pay page with 10-minute expiry, TrxID submit, single-view links, and round-robin wallet rotation.
- Withdraw gate (`Proxy/api/withdrawGate.php` + `withdrawIntent.php`): holds every `POST */transaction/withdraw` as `Pending` until an admin approves exactly one upstream debit. Optimistic balance holds keep the UI honest.

**Operator tooling**

- Admin panel (`/admin`): banners, marquee, games catalogue switches, voucher/payments, MFS wallets and channels, withdraw queue, users, SEO titles, logo/favicon, cache tools.
- First-run installer (`/setup`), server-side mirror benchmark (`upstreams.php`), JSON-file DB with atomic writes.

---

## 2. How it works

```
                        ┌────────────────────────────────────────────┐
                        │              This repo (BBC99)             │
                        │                                            │
Browser ──► router.php ─┤─► Proxy/router.php ─► Proxy/index.php ───┼──► upstream
                        │         │                    │            │    go444.io
                        │         ├─ /admin/* ─► admin/panel.php     │
                        │         ├─ /setup   ─► setup.php          │
                        │         ├─ /api/*   ─► api/*.php (local)  │
                        │         ├─ /voucherCenter/* ─► voucher    │
                        │         └─ /m/assets/* ─► mobile/assets/* │
                        │              + mobile/*.html (raw)        │
                        └────────────────────────────────────────────┘
                        real files (css/, images/, Pay.html, …) served as-is
```

**Request lifecycle** (all in `Proxy/index.php` unless noted):

1. **Base detection.** Mount base is derived from `REQUEST_URI` (never `SCRIPT_NAME`), `/Proxy/*` 301s to clean `/*`. Any `..` or `\` is a `400`.
2. **Custom pages.** Home, login, register, and game shell are served from `mobile/*.html` byte-for-byte — no rewriting, no shims, no splash.
3. **Local assets.** `/m/assets/*` with ETag revalidation, `/favicon.ico`, and a self-unregistering `/sw.js` + `/m/sw.js` stub that kills the upstream service worker before it can serve stale shells.
4. **Local routes.** `/admin`, `/setup`, `/voucherCenter` (only when enabled), the configurable voucher path, block `*.apk` / `*.ipa`.
5. **Cache lookup.** Fresh disk entry → `HIT`. Expired + degraded upstream → serve stale (1-in-20 revalidates). Missing entry → single-flight fill lock so 50 concurrent visitors cause 1 upstream fetch.
6. **Withdraw gate.** Encrypted withdraw POSTs are queued as `Pending` unless a single-use `Approved` exists for that session.
7. **Fetch.** `fetchUpstream()` walks `upstreams.json` fastest-first with per-mirror timeouts, HTTP/2, IPv4, shared curl handle, and forwards method, body, and all auth/crypto headers with the visitor's real User-Agent.
8. **Transform.** Brand replace, invite-domain fix, content/banners/marquee/APK/register/withdraw patches, `/res/*` path normalization, and (HTML only) combined shims + SEO + boot shim.
9. **Respond.** Per-traffic `Cache-Control` (`private,no-store` for auth/errors/authed JSON; `no-cache` for HTML; `immutable` for static), `X-Proxy: true`, then `finishRequestEarly()` so cache writes and GC never delay the visitor.

---

## 3. Repository structure

```
.
├── index.php                  # stub → Proxy/index.php
├── router.php                 # php -S router: static files as-is, else Proxy/router.php
├── .htaccess                  # Apache: /admin, /setup, /api/*, real files, else Proxy/index.php
├── nginx.conf                 # nginx equivalent
├── Procfile                   # web: cd Proxy && php -S 0.0.0.0:$PORT router.php
├── composer.json              # metadata only (php >= 8.1, no packages)
├── Pay.html css/ images/ img/ # voucher pay-page template + static assets
├── sitemap.xml robots.txt
│
├── Proxy/
│   ├── index.php              # front controller (~3100 lines): the whole edge
│   ├── router.php             # static / admin / setup / voucher / api dispatcher
│   ├── config.php             # GENERATED by /setup (upstream, brand, TTL, admin…)
│   ├── setup.php              # installer + re-config (locked after first run)
│   ├── upstreams.php          # mirror benchmark (web ?key= or CLI)
│   ├── upstreams.json         # fastest-first mirror order
│   ├── sitemap.xml
│   ├── api/                   # local JSON API (see §7)
│   │   ├── createOrder.php getOrder.php getOrderStatus.php
│   │   ├── submitTransaction.php consumeOrder.php
│   │   ├── getSetting.php gameSettings.php
│   │   ├── withdrawIntent.php
│   │   └── withdrawGate.php   # included pre-fetch, not a route
│   ├── admin/
│   │   ├── index.php          # /admin front controller → handleAdmin()
│   │   ├── panel.php          # all screens + POST actions (~2700 lines)
│   │   ├── config.php         # admin creds view over ../config.php (web-denied)
│   │   ├── store.php          # JSON DB helpers (content, orders, users, games…)
│   │   └── includes/functions.php  # uuid, currency, badges
│   ├── data/*.json            # runtime DB (web-denied, see §17)
│   ├── cache/                 # upstream asset cache + health/failover state
│   └── images/{banks,icons}/  # legacy proxied icons
│
├── mobile/
│   ├── index.html login.html register.html game.html 404.html
│   └── assets/
│       ├── api.js             # PXAPI upstream client (see §8)
│       ├── app.js             # auth forms behaviour
│       ├── home.js            # home grid behaviour (PXAPI only)
│       ├── config.js          # PX_MERCHANT + game groups
│       ├── app.css home.css
│       └── img/               # bkash/nagad/rocket/logo/favicon
│
├── voucherCenter/
│   ├── index.php              # deposit landing (built from index.html + JSON config)
│   ├── payment.php            # per-order pay page (built from Pay.html + order)
│   └── <METHOD>/…             # per-method icons + deposit-info.html
│
└── tools/                     # audit_functions.php, render_admin_preview.php (dev only)
```

Supporting notes live in `problem-to-fix.md` (browser E2E findings, all fixed) and `plan-for-member-home.md` (custom member-home plan, not yet built).

---

## 4. Getting started

**Requirements:** PHP `>= 8.1` with `curl`, `json`, `mbstring`; writable `Proxy/cache` + `Proxy/data`; Apache with `mod_rewrite` (or nginx / any PHP host). Upstream auth is header-based, so `Authorization` must reach PHP (`CGIPassAuth On` is already in both `.htaccess` files).

```bash
# 1. clone
git clone https://github.com/asifbadda/go444.git
cd go444

# 2. dev server — ALWAYS with the router, otherwise
#    /m/assets/*, /wps/*, /js/* 404 before PHP runs
php -S 127.0.0.1:8000 router.php
# production-style (what the Procfile does):
# cd Proxy && php -S 0.0.0.0:$PORT router.php
```

**First run:**

1. Open `/setup`. Fields:

   | Field | Example | Notes |
   |---|---|---|
   | Upstream URL | `https://www.go444.io` | the site being proxied; `Test upstream` button checks it live |
   | User agent | Chrome 125 string | sent only when the visitor sends none |
   | Cache TTL (s) | `3600` | `0` disables disk cache (`/res/*` then 302s to upstream CDN) |
   | Disable affiliate redirect | checked | keeps sub-domains serving this proxy instead of bouncing to `www.<root>?affiliateCode=` |
   | Replace this text / with | `go444` / `BBC99` | display-text brand swap; domains and auth keys are never touched |
   | Admin key | long random string | URL secret (`?key=…`) that unlocks `/setup` later |
   | Admin username / password | `admin` / 8+ chars | `password_hash` into `Proxy/data/users.json` |
   | Session cookie | `px_sid` | admin session name |

   Saving writes `Proxy/config.php` atomically and locks the installer.

2. Open `/admin` (login with the user you just created) and configure titles, logo, payments, games, and withdrawals (§12).
3. Optionally rank mirrors: `/Proxy/upstreams.php?key=<admin key>&mirrors=https://a,https://b&save=1`, or `php Proxy/upstreams.php https://a https://b`.

---

## 5. Configuration reference

**`Proxy/config.php`** (generated — edit via `/setup` or `/admin/settings`, never by hand in prod):

```php
return array (
  'upstream' => 'https://www.go444.io',
  'brand_from' => 'go444',
  'brand_to' => 'BBC99',
  'cache_ttl' => 3600,
  'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) … Chrome/125.0.0.0 Safari/537.36',
  'referral_code' => 'ggp0537',          // posted by the register form itself
  'referral_affiliate_code' => '',
  'reg_mobile_pattern' => '^01[3-9]\\d{8}$',
  'reg_username_pattern' => '^[A-Za-z][A-Za-z0-9]*$',
  'disable_affiliate_redirect' => true,
  'admin' => array (
    'key' => '…', 'user' => 'admin',
    'pass_hash' => '$2y$10$…', 'cookie' => 'px_sid',
  ),
);
```

Derived constants in `Proxy/index.php`: `UPSTREAM`, `CACHE_DIR/TTL`, `UPSTREAM_FAIL_TTL=120`, `UPSTREAM_FAILOVER_BUDGET=10`, `UPSTREAM_SLOW_MS=2000`, `UPSTREAM_DEGRADED_TTL=30`, `DEGRADED_REVALIDATE_1_IN=20`, `CACHE_FILL_WAIT_MS=2500`, plus `BRAND_*`, `REFERRAL_*`, `REG_*_PATTERN`, `BRAND_SKIP_KEYS`.

**Content and payments** (edited in `/admin`, stored as JSON):

- `Proxy/data/content.json` — `banners[]`, `marquee{enabled,items[{text,link}],bg,color,speed}`, `titles{web_title,mobile_title,app_name}`, `logo{url,width}`, `favicon{url}`, `voucher{enabled,path,redirect_url,amounts[],methods{}}`, `games{…}`.
- `Proxy/data/settings.json` — `{platformName:"VoucherCenter", brandName:"BBC99", currency:"BDT", currencySymbol:"৳", timeZone:6, language:"bn", isTest:false, favicon:"", logo:""}`.
- `Proxy/data/payment-methods.json` — the real deposit config:

```json
{
  "methods": {
    "BKASHSM": {
      "name": "bKash Send Money", "enabled": true,
      "color": "#E2136E", "logo": "/images/brand/mfs_bkashsm.svg?v=1791310333",
      "accounts": [
        { "number": "01336710020", "name": "Demo Merchant SM", "enabled": true,
          "channels": [{ "name": "চ্যানেল ১৮", "enabled": true, "min": 100, "max": 30000 }] }
      ]
    }
  },
  "amounts": [100, 200, 300, 500, 1000, 3000, 5000, 10000, 30000]
}
```

- `Proxy/upstreams.json` — `{order:["https://www.go444.io"], updated:"…", measured:"manual|server"}`. The configured upstream is always appended, so a missing file just means “one mirror”.

---

## 6. Routing reference

`Proxy/router.php` (dev) and `.htaccess` (Apache) agree on this table; everything else falls through to `Proxy/index.php`.

| Request | Served by | Notes |
|---|---|---|
| `/`, `/index`, `/index.html`, `/index.php`, `/home` | `mobile/index.html` | custom home, raw |
| `/m`, `/m/`, `/m/index`, `/m/index.html`, `/m/home` | `mobile/index.html` | trailing slash normalizes to same page |
| `/m/login` | `mobile/login.html` | logged-in visitors `replace()` to `/m/home` |
| `/m/register` | `mobile/register.html` | referral prefilled, captcha only (no OTP) |
| `/m/game` | `mobile/game.html` | in-app frame + Back; relay called exactly once |
| `/m/account` → `/m/member/home` | `302` | legacy shortcut |
| `/m/deposit` → `/m/voucherCenter` | `302` | legacy shortcut |
| `/m/assets/*` | `mobile/assets/*` | ETag, `no-cache,must-revalidate` |
| `/favicon.ico` | `mobile/assets/img/favicon.png` | upstream ships a 1px placeholder |
| `/sw.js`, `/m/sw.js`, `/m/sw.js*` | self-unregistering stub | `no-store`, kills upstream worker + its caches |
| `/admin`, `/admin/*` | `Proxy/admin/index.php` | hidden panel; unauth → site root |
| `/setup`, `/setup/`, `/setup.php` | `Proxy/setup.php` | locked after install (`?key=` or session) |
| `/api/*` | `Proxy/api/*.php` | existing `*.php` executes, else `404 {"error":"Not found"}` |
| `/voucherCenter`, `/voucherCenter/*` | `voucherCenter/index.php` + assets | only when `voucher.enabled`, else `404 Voucher Center disabled` |
| `/<voucher.path>` (default `/m/voucherCenter`) | `voucherCenter/index.php` | admin-controlled local route |
| any URL containing `voucherCenter` | `voucherCenter/index.php` | when enabled (case-insensitive) |
| `*.apk`, `*.ipa` | `404` | web-app installs only, via browser |
| real file (non-PHP) in docroot | served as-is | `mobile/`, `images/`, `css/`, `Pay.html`, `robots.txt`… |
| everything else | proxied upstream page | full fetch + rewrite pipeline (§9) |
| `/Proxy/*` | `301` to clean `/*` | prefix is hidden at PHP level too |

`/wps/*`, `/lgw/*`, `/js/*`, `/css/*`, `/common/*`, `/res/*` are backend/asset paths: proxied (never rendered as custom pages). Unknown navigations render the upstream SPA, which redirects them to `/m/home`.

---

## 7. Local API reference (`/api/*`)

All endpoints speak JSON with `Access-Control-Allow-Origin: *` and answer `204` to `OPTIONS`. `Proxy/router.php` maps `/api/<file>.php` to `Proxy/api/<file>.php`.

### 7.1 Deposit orders — manual MFS flow

Order lifecycle: `WaitingConfirm` (10 min) → `Confirmed` (TrxID submitted) → terminal `Expired` (swept on view) / `Failed` (admin). `consumed` is orthogonal single-view flag.

**`POST /api/createOrder.php`** — open a deposit order.

```bash
curl -X POST /api/createOrder.php \
  -H 'Content-Type: application/json' \
  -d '{"method":"BKASHSM","amount":500,"channel":"চ্যানেল ১৮"}'
```

```json
{ "success": true, "trackingNumber": "uuid-v4", "expiresAt": "2026-10-06T…" }
```

- `method` must exist and be enabled in `payment-methods.json`; `channel` must be enabled on at least one enabled account (exact `accountNumber` match optional — without it, multiple eligible wallets rotate via `payment_rotation_next()` with `flock`).
- Amount must fall inside the matched channel's `[min,max]` (defaults `100–30000`).
- Errors: `405 POST required` · `400 Missing method, amount, or channel` / `Invalid payment method` / `Method is disabled` / `Channel "…" not available` / `Amount out of range (min - max)`.

**`GET /api/getOrder.php?trackingNumber=<uuid>`** (`tracking=` also works) — full order view for the pay page:

```json
{
  "PlatformName": "VoucherCenter", "TrackingNumber": "…",
  "PaymentChannelName": "চ্যানেল ১৮", "CurrencyName": "BDT",
  "Amount": 500, "RealAmount": 500, "AccountNumber": "01336710020",
  "AccountName": "bKash Send Money", "OrderStatusName": "WaitingConfirm",
  "ExpiredAt": "…", "CreatedAt": "…",
  "PayerAccountNumber": null, "TransferCode": null
}
```

`400 trackingNumber is required` · `404 Order not found`.

**`GET /api/getOrderStatus.php?trackingNumber=<uuid>`** — tiny poll endpoint:

```json
{ "Id": 1001, "TrackingNumber": "…", "OrderStatusId": 1 }
```

`WaitingConfirm=1, Confirmed=2, Expired=3, Failed=4`, unknown → `0`.

**`POST /api/submitTransaction.php`** — bind the payer's TrxID (JSON or form; `transferCode` accepted as alias):

```bash
curl -X POST /api/submitTransaction.php \
  -H 'Content-Type: application/json' \
  -d '{"trackingNumber":"…","payerAccount":"013xxxxxxx","trxId":"ABC123"}'
```

```json
{ "success": true, "message": "Transaction confirmed",
  "trackingNumber": "…", "status": "Confirmed" }
```

`WaitingConfirm → Confirmed + confirmedAt`; resubmitting a `Confirmed` order only patches `payerAccount`/`trxId`. Non-pending orders get `{error:"Order is not pending", status}`.

**`POST /api/consumeOrder.php`** — single-view: call on pay-page close/back via beacon; afterwards `payment.php` renders “link used” and never shows the wallet again. New deposits mint new tracking numbers, unaffected.

```json
{ "success": true, "trackingNumber": "…" }
```

Order record (`orders.json`, `nextId` from `1001`):

```json
{ "id": 1001, "trackingNumber": "uuid", "paymentMethod": "BKASHSM",
  "paymentChannel": "চ্যানেল ১৮", "accountNumber": "01336710020",
  "amount": 500, "payerAccount": null, "trxId": null,
  "status": "WaitingConfirm", "createdAt": "…",
  "expiresAt": "…", "confirmedAt": null }
```

### 7.2 Settings and games (public, no secrets)

**`GET /api/getSetting.php`**

```json
{ "Country": "Bangladesh", "EnableReturnAmount": false, "EnableStoreMode": false,
  "Id": 1, "IsTest": false, "Language": "bn", "PlatformName": "VoucherCenter",
  "TimeZone": 6, "Currency": "BDT", "BrandName": "BBC99" }
```

**`GET /api/gameSettings.php`** (`Cache-Control: no-store` — panel changes are live next load):

```json
{ "success": true, "games": {
    "enabled": true, "language": "EN", "in_app": true,
    "back_url": "/m/home", "page_size": 24,
    "types": { "RNG": true, "LIVE": true, "PVP": true, "SPORTS": true,
               "FISH": true, "ELOTT": true, "ESPORTS": true },
    "vendors": { "JL": true, "PG": true, "JDB": true },
    "hidden": [], "updated": "2026-10-06T…" } }
```

A code absent from `types`/`vendors` counts as **enabled** — fresh installs offer everything; only explicit `false` hides. Consumed by `PXAPI.loadSettings()` with identical fallback defaults.

### 7.3 Withdraw intent (local beacon)

**`POST /api/withdrawIntent.php`** — plaintext `{amount, cardHint|card}` posted by the client shim *just before* the encrypted withdraw submit (PHP cannot read the amount from the RSA/DES body). Same session fingerprint as the gate: `md5(Cookie + Authorization + Encryption + X-Gateway-Version)`.

- Patches the newest `Pending` row for that session (≤10 min, amount still `0`), or creates a `fromIntent:true` placeholder so the amount survives even if the encrypted submit lands a moment later.
- `405 POST required` · `401 Not logged in` (anonymous fingerprint).

---

## 8. Upstream API via `PXAPI` (`/wps/*`)

`mobile/assets/api.js` exposes `window.PXAPI`; `app.js` / `home.js` are the only callers. Same-origin URLs are proxied automatically.

**Every request carries:** `Accept: application/json`, `Content-Type: application/json;charset=UTF-8`, exactly one `Merchant` (priority `window.PX_MERCHANT` > `<meta name="px-merchant">` > `localStorage.px_merchant`; configured `go44bdtf5` in `config.js` — must match the active upstream), `Device: web`, `Language` (from `localStorage.hisLang` or `en`), `Authorization: <token>` when logged in (from `sessionStorage token | MC_SESSION_INFO | login`), and `X-Real-UA` (base64 UA).

**Encrypted endpoints** (`login`, `register`, `registerMobile`) load proxied `/js/encrypt.js` then `reRsaV2(payload)` → body `{value: DES}` with `Encryption: RSA`, `X-Digest: DES`, `X-RSA: rsaKey`. The proxy forwards all three — dropping the latter two is the historic `400 request_body_required` login failure. Plain-JSON fallback fires only if `encrypt.js` never loaded.

| `PXAPI.*` | Upstream endpoint | Payload / notes |
|---|---|---|
| `login(p)` | `POST /wps/session/login` (enc) | `{username (=mobile/mobileNum alias), password, type:"username", loginDeviceId}`; `value.token` persisted like upstream |
| `loginDeviceId()` | — | persisted UUID `localStorage SHELL_deviceId` (upstream anti-fraud field) |
| `logout()` | `POST /wps/session/logout` | token cleared even on failure |
| `register(p)`, `registerMobile(p)` | `PUT /wps/member/register`, `PUT …/register/mobile` (enc) | `{mobile, password, smsCode, inviteCode?}` + deviceId |
| `registerAuto` | `PUT /wps/member/register/autoUsername` | |
| `registerSetting`, `countryCode` | `GET /wps/system/setting/register`, `GET /wps/system/country` | |
| `sendSms`, `sendLoginSms` | `POST /wps/verification/sms/{register,noLogin}` | |
| `captcha`, `captchaImage`, `captchaGeetest` | `GET /wps/captcha[?t=]` (+geetest) | `no-store`; image → data-URL (handles `value` / `value.img|image|base64|captcha`) |
| `memberInfo`, `balance` | `GET /wps/member/info`, `GET /wps/member/info/funds/consolidated` | |
| `gameList` | `GET /wps/relay/GCSGAME_gameList` | GET-query relay (`merchant, platform, gameType, pageNo, pageSize…`) |
| `hotGames` | `POST /wps/relay/GCSGAME_hotGamesV2` | |
| `gameVendors`, `gameMenus`/`gameTypes` | `GET /wps/relay/GCSGAME_newGameVendor`, `GET /wps/relay/GCSGAME_getVassGameType` | vendor-per-type map |
| `winnerBoard` | `GET /wps/relay/GCSGAME_getRankList` | `{gameCategory, language, limitNum}` |
| `launchGame(p)` + `gameUrl(res)` | `GET /wps/game/launchGame` | defaults `launchMode:"GLS"` (missing = `#727 system busy` 500), `accountType:1` (real money; `0` = free play), `language:<panel>` (missing = Chinese vendor default); URL read from `value.content.game_url` or `value.url|gameUrl|link|launchUrl` |
| `announcements` | `GET /wps/relay/CCSFE_getListAnnouncements` | |

Resilience: a stored token the upstream rejects (`401` on non-login calls) is dropped and the request retried once as guest, so public feeds still render instead of blanking the page. `reg_info` affiliate leftovers from older builds are scrubbed on load.

```js
// login then load the catalogue (frontend pattern)
await PXAPI.login({ mobile: '01336710020', password: '…' });
await PXAPI.loadSettings();                       // /api/gameSettings.php
const games = await PXAPI.gameList({
  merchant: PX_MERCHANT, platform: 'WEB',
  gameType: 'RNG', pageNo: 1, pageSize: 24,
});
```

---

## 9. Proxy engine deep dive

**Caching.** Cacheable = `CACHE_TTL > 0` + `isCacheable(path)` + not `/` or `/index.php`. Fresh entries serve with `X-Proxy-Cache: HIT`-style semantics; expired entries serve stale while degraded (1-in-20 still revalidates so the cache crawls forward); missing entries take a per-file `flock` fill lock and peers wait `≤2500 ms` for the filler. `writeCacheFile()` + `gcCache()` run *after* `finishRequestEarly()`, so disk work never holds a PHP slot. HTML is always fetched fresh (needed for injection); assets poisoned with HTML bodies answer real `404` instead of caching the shell.

**Mirrors and health.** `upstreamMirrors()` = `upstreams.json:order` + configured upstream (never lost). `activeUpstream()` honors `cache/upstream-fail.json` demotions (`120 s`); each request gets a `10 s` sweep budget across mirrors. Results feed `cache/upstream-health.json`: one slow (`>2000 ms`) or failed fetch opens a `30 s` degraded window with fast-fail timeouts. Single mirror = 2 attempts; several = 1 attempt each, demote only on no-answer (a lone `5xx` is retried, not demoted).

**Rewriting.** `rewriteAndCache()` normalizes quoted, unquoted, and CSS `url()` `/res/*` references to local paths (assets lazy-cache on browser request — the old eager-download made first views wait on dozens of blocking fetches). `applyBrand()` walks JSON (skipping `BRAND_SKIP_KEYS`) and display text (longest variant first, URL/domain-guarded). `applyInviteDomain()` repoints leftover upstream share domains to the current host. `applySupportUrl()` (`store.php`) rewrites chat/CS URLs to `SUPPORT_URL`. `applyContentJson/Html`, `applyMarqueeJson`, `applyBannersJson`, `applyApkPolicy`, `applyRegisterRules` (mobile/username regexes from config), `applyWithdrawBalance/Report` (hold subtraction + merged statuses), and `applyScriptPatches` (e.g. affiliate-subdomain redirect off) complete the pipeline.

**Shims and SEO.** `injectCombinedShims()` (base, history patch for SPA navigation onto custom routes, referral scrub), `injectAppIconShim()` (`/img/web_app_icon.png`), `injectSeoShim()`, `injectBootShim()` (hides upstream loader, blocks promo popups on `/m*`). `domainRoute 400 request_param_err` gets a minimal success so the SPA stays online; `WPSCORE_checkIfAppDomain` is pinned falsy so the upstream anti-clone wipe/redirect never fires. Responses set `X-Proxy: true`, correct `Vary: Cookie` on private traffic, and `Link: preload` for the vendor CSS on HTML.

---

## 10. Deposit flow (VoucherCenter)

```
voucherCenter/index → POST /api/createOrder → confirm popup
  → payment.php?tracking=UUID (Pay.html + order: wallet, amount, color)
  → user pays to shown wallet, enters TrxID → POST /api/submitTransaction
  → admin confirms in /admin · user polls GET /api/getOrderStatus
  (close/back → POST /api/consumeOrder → link burned)
```

1. `voucherCenter/index.php` renders `index.html` with live methods, logos, amounts, channels, brand/title/favicon, canonical + JSON-LD SEO, and per-method enable (disabled methods are hidden server-side so they never flash). The submit button calls `createOrder.php` and shows the Bengali confirm modal.
2. `payment.php?tracking=` loads the order, resolves the wallet (stored number, or peeked round-robin, or first enabled fallback), expires it at 10 min (`Expired`), and injects everything into `Pay.html`: method label/color/icon, wallet number with copy, amount, cashout-vs-send-money wording, countdown bar, TrxID field with confirm overlay, edit flow (SweetAlert), close button (`location.replace()` + `pageshow` guard so Back can never restore the wallet), and `noindex`.
3. The user pays from their own wallet, submits the TrxID, and the order flips to `Confirmed`. Admins reconcile in `/admin` → Voucher & Payments.

---

## 11. Withdraw approval flow

```
client shim → POST /api/withdrawIntent {amount, cardHint}   (plaintext beacon)
client     → POST /wps/…/transaction/withdraw               (encrypted body)
proxy gate → Pending queue  OR  consume Approved → upstream debit (once)
admin      → /admin/withdrawals: Approve / Reject
UI         → balance minus active holds + merged report status
```

- **States:** `Pending` → `Approved` (single-use; `consumedAt` set on first upstream passthrough) or `Rejected`; rows expire after 24 h. Same-payload retries within 60 s reuse the row (no queue spam).
- **Identity:** `md5(Cookie + Authorization + Encryption + X-Gateway-Version)` — anonymous requests skip the gate and get the upstream `401` as usual.
- **UX contract:** the queued response is `200 {success, queued:true, approvalStatus:"Pending", approvalId, value:null, message:"…Waiting for admin approval…"}`, so the upstream app treats it as received, not failed. The user retries manually after approval; exactly one retry reaches upstream.
- **Admin:** `/admin/withdrawals` shows pending count as a nav badge, session hint (UA), amount, card hint, age, and approve/reject actions.

---

## 12. Admin panel guide

`/admin` → `admin/index.php` → `handleAdmin()` in `panel.php`. Login is username + `password_verify` with 10-try throttling, `session_regenerate_id`, CSRF on every POST, `?key=<admin key>` backdoor, and silent redirect home for strangers. `config.php` / `panel.php` and `admin/includes/` are web-denied in `Proxy/.htaccess`.

| Group | Section (`/admin/…`) | What it controls |
|---|---|---|
| Overview | Dashboard (`/admin`) | health, quick actions, cache size + purge |
| Appearance | Titles, Logo, Favicon, App name | `web_title`, `mobile_title`, `app_name`; uploads/URLs → `/images/brand/` (≤3 MB, cache-busted `?v=`) |
| Content | Banners | `[{image,link,title,active}]` injected into game JSON |
|  | Marquee | on/off, `[{text,link}]`, `bg/color/speed` |
|  | Games | master on/off, language (`EN/BN/ID/TH/VI/ZH`), in-app shell + `back_url`, `page_size` 4–120, category map, vendor map (live from relay, ~84 codes; full code list posted so off stays off), `hidden[]` game IDs |
|  | Voucher & Payments | `enabled`, `path` (`/m/voucherCenter`), `redirect_url` (`/voucherCenter`), amounts, legacy method defaults |
| Payments | Pay Settings | `platformName`, `brandName`, `currency`, `timeZone`, `language`, `isTest`, logo/favicon overrides |
|  | Voucher & Payments (methods) | per-method `enabled/color/logo`, wallets `{number,name,enabled}`, channels `{name,enabled,min,max}`, quick `amounts[]`; MFS tiles with live wallet/channel counts |
|  | Withdrawals | `Pending/Approved/Rejected` queue with badge count, approve/reject/consume |
| Access | Users | owner/admin list, add, delete, password reset |
| System | Settings | upstream, brand pair, TTL, UA, affiliate-redirect switch (atomic `config.php` rewrite) |
|  | Tools | cache size, purge, mirror state, debug notice |

Every write is atomic (`.tmp` + `rename`; `flock` for rotation counters). Games and voucher changes are live on next page load — no cache clear needed.

---

## 13. Frontend guide (`mobile/`)

| File | Route | Purpose |
|---|---|---|
| `index.html` | `/`, `/home`, `/m`, `/m/home`, … | home: categories, provider filter + search, games grid, winner board, banners |
| `login.html` | `/m/login` | login form + upstream image captcha |
| `register.html` | `/m/register` | registration + prefilled referral, captcha only |
| `game.html` | `/m/game` | full-screen frame, own Back/reload, Try-again on failure |
| `404.html` | (reserved) | ready if a route ever needs a local 404 |
| `assets/api.js` | `/m/assets/api.js` | `PXAPI` — the only backend wiring |
| `assets/app.js` | `/m/assets/app.js` | auth page behaviour |
| `assets/home.js` | `/m/assets/home.js` | home behaviour, talks only through `PXAPI` |
| `assets/config.js` | `/m/assets/config.js` | `PX_MERCHANT='go44bdtf5'`, `PX_GAME_GROUPS` must mirror `games_type_defaults()` |
| `assets/app.css`, `home.css` | — | self-contained design systems, own icons/colors |
| `assets/img/` | — | `bkash/nagad/rocket/logo/favicon/jackpot-bg.png` — local only |

Rules: never point these pages at upstream bundles or another host's assets (one exception: Material Symbols Rounded for category pills, written as codepoints so offline = empty pills, not words). Assets revalidate via ETag — but still bump `?v=` in the page after CSS changes, because browsers that fetched during the old `immutable` window keep that copy until expiry. Login sessions are shared: `PXAPI` writes `token` + `MC_SESSION_INFO` in the exact shape the upstream bundle reads, so a member logged in on our page is already signed in on proxied pages.

---

## 14. Brand and MFS logos

**Where they live:**

- MFS method icons (tracked): `images/brand/mfs_bkash.svg`, `mfs_bkashsm.svg`, `mfs_nagad.svg`, `mfs_nagadsm.svg`, `mfs_rocket.webp`, `mfs_usdt.svg` — referenced from `payment-methods.json` as `/images/brand/<file>?v=<mtime>`.
- Site logo / favicon (configured): `Proxy/data/content.json → logo.url / favicon.url` plus `settings.json → logo / favicon` overrides. Current values point at `imageurls.kesug.com` uploads; localize them with `/admin` → Logo/Favicon (upload or URL fetch → `/images/brand/logo.*|favicon.*`, old file cleared, `?v=` appended).
- Frontend icons: `mobile/assets/img/{bkash,nagad,rocket,logo,favicon}.png` for the custom home; `voucherCenter/<METHOD>/*.png` for the deposit desk; `Proxy/images/{banks,icons}/` for legacy proxied paths.

**Replacing a logo:** prefer `/admin` (validates type/size, clears the old variant, writes the cache-busted URL everywhere). Direct file swaps work too — keep the same filename and bump any hardcoded `?v=` if browsers look stale. Keep SVGs for MFS marks (crisp at any size) and PNG/WebP for photos; 3 MB max via the panel.

---

## 15. Deployment

**Apache (recommended):** point the vhost at the repo root; root `.htaccess` handles `/admin`, `/setup`, `/api/*`, real files, and the `Proxy/index.php` fallback, with `CGIPassAuth On` + `Authorization` preservation. `Proxy/.htaccess` denies `config.php`, `panel.php`, `admin/includes/`, and `data/` JSON.

**nginx:** translate `nginx.conf` (`/admin`, `/setup`, `/VoucherCenter`, `/ → index.php`, `*.php → php-fpm` with `SCRIPT_FILENAME`). Ensure `Authorization` and the `Merchant/Device/Language/Encryption/X-Digest/X-RSA` headers are forwarded.

**PHP built-in / PaaS:** `Procfile` runs `cd Proxy && php -S 0.0.0.0:$PORT router.php`. From the root, `php -S 127.0.0.1:8000 router.php` behaves the same. Required extensions: `curl`, `json`, `mbstring`. Make `Proxy/cache` and `Proxy/data` writable by the PHP user.

**After cloning:** run `/setup` fresh (never reuse the shipped `config.php` key/hash), create a real admin password, set `cache_ttl` (`3600` prod, `0` to debug uncached), configure mirrors with `upstreams.php`, and keep `Proxy/data/*.json` out of backups/screenshots (orders carry phone numbers and TrxIDs).

---

## 16. Security

- **Secrets:** `Proxy/config.php` (`admin.key`, `pass_hash`) and `Proxy/data/users.json` are the crown jewels — never commit, log, or screenshot them. Rotate both after cloning.
- **Auth:** admin sessions are `HttpOnly`, `SameSite=Lax` (`Secure` when HTTPS), with CSRF tokens, login throttling, and `password_hash`/`password_verify`. `?key=` is a recovery path, not a daily driver — use passwords.
- **Denials:** `Proxy/.htaccess` + `data/.htaccess` block direct web reads of config, panel source, includes, and JSON DB. `..` / `\` paths are rejected at both routers before touching disk or cache.
- **Privacy:** authed JSON, errors, and auth traffic are `private,no-store` end-to-end (browser, proxy, middleboxes). Withdraw/order PII lives only in `Proxy/data/*.json` — restrict host access.
- **Upstream trust:** `CURLOPT_SSL_VERIFYPEER` stays on; `CURLOPT_RESOLVE` pins DNS per fetch; game `back_url` must be same-origin; brand assets are type-sniffed (`getimagesizefromstring` + `<svg>` check) before saving.
- **Abuse surface:** captcha is `no-store` + timestamped; withdraw approvals are single-use and session-bound; consume-once pay links limit wallet harvesting; APK/IPA serving is disabled.

---

## 17. Storage and data files

All in `Proxy/data/` (created on demand, pretty-printed JSON, atomic writes). `store.php` is the only writer — never hand-edit in prod while PHP runs.

| File | Helpers | Contents |
|---|---|---|
| `content.json` | `content_load/save`, `games_config`, `voucher_*` | banners, marquee, titles, logo, favicon, voucher, games |
| `settings.json` | `payment_settings_read/write` | platform/brand/currency/timezone/language/test/favicon/logo |
| `payment-methods.json` | `payment_methods_data_*` | methods → accounts → channels + quick amounts |
| `orders.json` | `orders_*, find_order_by_tracking, update_order` | `{orders[], nextId}` deposit orders |
| `payment-rotation.json` | `payment_rotation_{read,write,next,peek}` | `"METHOD:channel" → last index` round-robin |
| `withdraw-approvals.json` | `withdraw_approvals_*, withdraw_approval_{find_usable,consume}, withdraw_active_hold_sum` | `{items[], nextId}` withdraw queue |
| `users.json` | `users_{load,save,seed,next_id}`, `user_verify` | `{id,username,pass_hash,role,created}[]` |
| `games-vendors.json` | (panel) | live vendor catalogue snapshot |

`Proxy/cache/` holds fetched assets plus `upstream-health.json` (degraded window) and `upstream-fail.json` (active demotion) — safe to delete; they rebuild.

---

## 18. Tools and maintenance

- **Mirrors:** `php Proxy/upstreams.php https://m1 https://m2` (CLI saves) or `/Proxy/upstreams.php?key=<admin key>[&mirrors=a,b][&save=1]` (web prints TTFB/status/size/verdict table, refuses to save when nothing is usable). Measures **from the server**, because host routing is all the proxy ever uses.
- **Cache:** `/admin` → Tools shows size and purges; `gcCache()` also sweeps on writes. Deleting `Proxy/cache/*` by hand is safe.
- **Audit:** `php tools/audit_functions.php Proxy/index.php […]` lists called-but-undefined helpers (catches bad merges); `tools/render_admin_preview.php` renders panel screens without auth.
- **Logs:** PHP `error_log` carries upstream errors, degraded transitions, and mirror-sweep budgets — watch it after upstream incidents.
- **Docs:** `problem-to-fix.md` is the E2E fix log (captcha caching, `X-Digest` forwarding, `launchMode`, language, game shell, panel switches…); `plan-for-member-home.md` is the accepted Option-B plan for a custom `/m/member/home` aggregator (`GET /api/memberSummary.php`), not yet implemented.

---

## 19. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `/setup` redirects home | `config.php` already exists | append `?key=<admin key from config.php>` |
| `/admin` bounces to `/` | not logged in / bad key | login at `/admin/login`, or unlock with `?key=` |
| Login `400 request_body_required` | `X-Digest` / `X-RSA` not forwarded | fixed in `fetchFromUpstream()` — update `Proxy/index.php` |
| Login succeeds then shows guest | `Authorization` never sent | `PXAPI.setToken()` must persist `value.token`; check `CGIPassAuth` |
| Games `#727 system busy` 500 | `launchMode` missing | `PXAPI.launchGame` defaults `GLS` — update `api.js` |
| Games open in Chinese | `language` missing | defaults to panel language (`EN`) — check `/admin/games` |
| Launched game has no Back | opened outside `/m/game` | always route launches through the in-app shell |
| Bounced to a foreign domain | `reg_info` referral seeding | shims must scrub `referralCode/affiliateCode/inviteCode`, never write |
| Blank proxied pages | bad `$base` / shim syntax | derive base from `REQUEST_URI`, reject `/` as base |
| Upstream worker serves 503 shells | `/m/sw.js` not neutralized | stub must answer every `…/sw.js` path |
| Stale captcha image | cached `200` reused a day | path must be `private,no-store` + timestamped |
| Member feeds all `401` | dead stored token | drop + single guest retry (in `PXAPI.request`) |
| Site slow during upstream wobble | no stale serving / long timeouts | check degraded window, `upstream-health.json`, failover budget |
| Dead mirror stalls traffic | primary down, no demotion | run `upstreams.php?key=…&save=1`, inspect `upstream-fail.json` |
| Voucher `404` | `voucher.enabled` off / bad path | enable in `/admin`, path must contain `voucherCenter` |
| Pay page shows wrong wallet | channel matched several accounts | intentional round-robin — pin via explicit `accountNumber` in `createOrder` |
| TrxID edit asks twice | SweetAlert CDN blocked | falls back to native `confirm()` automatically |
| Logos stale after change | old `immutable` / `?v=` | re-save in `/admin` (new `?v=`), hard-reload, purge CDN if fronted |

---

## 20. FAQ

**Do I need a database?** No. The JSON files in `Proxy/data/` are the database. Back them up like one.

**Does the proxy store passwords?** Only admin `password_hash` values. Member credentials pass through to upstream (encrypted) and are never logged or stored here.

**Can I run in a subfolder?** Yes — base detection is request-based, and `/setup` shows the detected base. Keep the `/Proxy/* → /*` 301s and the `.htaccess` rewrites intact.

**How do I rebrand again?** `/admin` → Settings (upstream + brand pair + TTL + UA) and Appearance/Content (titles, logo, banners, marquee). JSON responses and display text follow automatically; domains and auth config never change.

**How do I add a wallet?** `/admin` → Voucher & Payments → method → wallet `{number, name, enabled}` → tick which channels it serves with per-channel `[min,max]`. Rotation across same-channel wallets is automatic.

**How do withdrawals reach upstream?** Exactly once per approval: approve in `/admin/withdrawals`, the user retries, the gate consumes the approval and lets that single POST through. Everything before approval is a local `Pending` — no upstream debit.

**Where do I get help?** Upstream member issues go to `support.bbc99.bet`. Repo issues: open a GitHub issue with the route, `X-Proxy`/`X-Withdraw-Gate` headers, and the relevant `error_log` lines (redact tokens and phone numbers).
