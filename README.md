# go444 Proxy — BBC99

> Reverse proxy, custom mobile frontend, and local deposit / withdraw system for the `go444` whitelabel — rebranded and operated as **BBC99**.

![PHP >= 8.1](https://img.shields.io/badge/PHP-%3E%3D%208.1-777BB4?logo=php&logoColor=white)
![No dependencies](https://img.shields.io/badge/dependencies-none-brightgreen)
![Storage](https://img.shields.io/badge/storage-JSON%20files-blue)
![Upstream](https://img.shields.io/badge/upstream-www.go444.io-orange)
![Brand](https://img.shields.io/badge/brand-BBC99-red)

**What this is:** a self-hosted PHP front door in front of `https://www.go444.io`. It serves its own home / login / register / game-shell pages, proxies everything else with brand rewriting and disk caching, and adds what the upstream does not give you — a manual mobile-money deposit desk and an admin-approved withdraw gate.

**What this is not:** a fork of the upstream casino software. Game logic, wallets, sessions, and member data stay on the upstream backend (`/wps/*`). This repo owns the edge: routing, presentation, branding, caching, payments UX, and moderation.

- **Stack:** PHP `>= 8.1`, zero composer packages, JSON-file storage, Apache / nginx / PHP built-in server.
- **Live entry point:** `Proxy/index.php`. Root `index.php` and `router.php` are thin stubs that delegate to it.

---

## Table of contents

1. [How it works](#1-how-it-works)
2. [Deposit](#2-deposit)
3. [Withdraw](#3-withdraw)
4. [Game](#4-game)
5. [Features](#5-features)
6. [Getting started](#6-getting-started)
7. [Local API reference (`/api/*`)](#7-local-api-reference-apistar)
8. [Upstream API via `PXAPI` (`/wps/*`)](#8-upstream-api-via-pxapi-wpsstar)
9. [Admin panel guide](#9-admin-panel-guide)
10. [Frontend guide (`mobile/`)](#10-frontend-guide-mobile)
11. [Brand and MFS logos](#11-brand-and-mfs-logos)
12. [Configuration reference](#12-configuration-reference)
13. [Routing reference](#13-routing-reference)
14. [Deployment](#14-deployment)
15. [Security](#15-security)
16. [Storage and data files](#16-storage-and-data-files)
17. [Tools and maintenance](#17-tools-and-maintenance)
18. [Troubleshooting](#18-troubleshooting)
19. [FAQ](#19-faq)

---

## 1. How it works

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

Every request goes through one pipeline in `Proxy/index.php`:

1. **Base detection.** Mount base comes from `REQUEST_URI` (never `SCRIPT_NAME`); `/Proxy/*` 301s to clean `/*`. Any `..` or `\` is a `400`.
2. **Custom pages first.** Home, login, register, and the game shell are served from `mobile/*.html` byte-for-byte — no rewriting, no shims, no splash. Only if the route is not ours does the proxy fetch upstream.
3. **Local assets and routes.** `/m/assets/*` (ETag revalidated), `/favicon.ico`, self-unregistering `/sw.js` stub, plus `/admin`, `/setup`, and `/voucherCenter` (only when enabled). `*.apk` / `*.ipa` are blocked.
4. **Cache lookup.** Fresh disk entry serves immediately. Expired entry + degraded upstream serves stale (1-in-20 still revalidates). Missing entry takes a per-file fill lock so 50 concurrent visitors cause 1 upstream fetch, not 50.
5. **Withdraw gate.** Encrypted withdraw POSTs are held as `Pending` unless a single-use admin approval exists for that session.
6. **Fetch.** `fetchUpstream()` walks mirrors fastest-first (`upstreams.json` + configured upstream), HTTP/2, IPv4, shared curl handle, 3s connect / 6s total, 10s sweep budget. It forwards the method, body, and all auth/crypto headers (`Authorization, Merchant, Device, Language, Encryption, X-Digest, X-RSA, Cookie`) with the visitor's real User-Agent.
7. **Transform.** Brand swap (`go444` + legacy variants → `BBC99`, domains/auth keys skipped), invite-domain repair to the current host, support-URL consolidation, content/banners/marquee/APK/register/withdraw patches, `/res/*` path normalization, and (HTML only) combined shims + SEO + boot shim.
8. **Respond.** Auth traffic, errors, and logged-in JSON go `private,no-store`; HTML goes `no-cache`; static goes `immutable`. Header `X-Proxy: true` marks every proxied answer. Cache writes and GC run after `finishRequestEarly()`, so disk work never delays the visitor.

In short: **ours renders locally, theirs proxies with rewriting, money moves through our local order/approval tables, and games stream from the upstream backend.**

---

## 2. Deposit

Manual mobile-money desk for **bKash, Nagad, Rocket, USDT** — method → channel → amount → per-order pay page → TrxID submit → admin reconcile.

```
voucherCenter/index → POST /api/createOrder → confirm popup
  → payment.php?tracking=UUID (wallet, amount, countdown)
  → user pays from their own wallet, enters TrxID
  → POST /api/submitTransaction → Confirmed
  → admin confirms in /admin · user polls /api/getOrderStatus
  (close/back → POST /api/consumeOrder burns the link)
```

**How each step behaves:**

- **Landing (`voucherCenter/index.php`).** Built per request from `index.html` + `payment-methods.json` + `settings.json` + `content.json`: live methods, logos, amounts, channels, brand/title/favicon, canonical + JSON-LD SEO. Disabled methods are hidden server-side so they never flash. Submit calls `POST /api/createOrder.php` and shows the Bengali confirm modal.
- **Order (`POST /api/createOrder.php`).** Body `{method, amount, channel, accountNumber?}`. The method must exist and be enabled; the channel must be enabled on an enabled wallet; the amount must sit inside that channel's `[min,max]` (default 100–30000). With several eligible wallets the proxy rotates round-robin (`payment_rotation_next()` with `flock`). Answers `{success, trackingNumber (uuid v4), expiresAt (+10 min)}` with status `WaitingConfirm`.
- **Pay page (`payment.php?tracking=`).** Rendered from `Pay.html` + the order: method label/color/icon, destination wallet with copy button, exact amount, cashout-vs-send-money wording, 10-minute countdown bar, TrxID field with confirm overlay and edit flow, close button that `location.replace()`s away (plus `pageshow` guard) so Back can never restore the wallet. Expired orders flip to `Expired` with an overlay. `noindex`, never cached.
- **Submit (`POST /api/submitTransaction.php`).** Body `{trackingNumber, payerAccount?, trxId|transferCode?}` (JSON or form). `WaitingConfirm → Confirmed + confirmedAt`; resubmitting a `Confirmed` order only patches fields. Anything else gets `{error:"Order is not pending"}`.
- **Poll (`GET /api/getOrderStatus.php`, `GET /api/getOrder.php`).** Tiny `{Id, TrackingNumber, OrderStatusId}` (`1=WaitingConfirm, 2=Confirmed, 3=Expired, 4=Failed`) for polling, and the full order view (platform, channel, currency, wallet, status, timestamps, payer, TrxID) for the pay page.
- **Burn (`POST /api/consumeOrder.php`).** Single-view flag (`consumed + consumedAt`) fired on close/back — afterwards the link shows “used” and the wallet never appears again. New deposits mint new tracking numbers.

Order record (`orders.json`, `nextId` from 1001): `{id, trackingNumber, paymentMethod, paymentChannel, accountNumber, amount, payerAccount, trxId, status, createdAt, expiresAt, confirmedAt, consumed?, consumedAt?}`.

Wallets and limits are fully operator-controlled: `/admin` → Voucher & Payments → per-method `enabled/color/logo`, wallets `{number, name, enabled}`, channels `{name, enabled, min, max}`, quick `amounts[]`.

---

## 3. Withdraw

Every upstream debit passes an admin. The upstream withdraw body is RSA/DES-encrypted so PHP cannot read the amount — a plaintext beacon arrives first, the gate holds the encrypted submit, and exactly one retry per approval reaches upstream.

```
client shim → POST /api/withdrawIntent {amount, cardHint}   (plaintext beacon)
client      → POST /wps/…/transaction/withdraw               (encrypted body)
proxy gate  → Pending queue  OR  consume Approved → upstream debit (once)
admin       → /admin/withdrawals: Approve / Reject
UI          → balance minus active holds + merged report status
```

**Gate logic (`Proxy/api/withdrawGate.php`, included pre-fetch — not a route):**

- Handles `POST` paths containing `/wps/v2/transaction/withdraw` or `/wps/transaction/withdraw` only.
- Identity = `md5(Cookie + Authorization + Encryption + X-Gateway-Version)`. Anonymous requests skip the gate (upstream `401` as usual).
- Usable `Approved` + unconsumed + unexpired approval for that session → `withdraw_approval_consume()` + header `X-Withdraw-Gate: approved-passthrough` → request falls through to upstream. Exactly one POST burns one approval.
- Otherwise the POST is queued: same-payload retries within 60s reuse the row; intent beacons younger than 10 min donate their amount/card hint; otherwise a fresh `Pending` row `{id, sessionKey, sessionHint(UA80), amount, cardHint, payloadHash, status:Pending, createdAt, decidedAt:null, consumedAt:null, expiresAt:+24h, fromIntent:false}` is written and answered locally:

```json
{ "success": true, "queued": true, "approvalStatus": "Pending",
  "approvalId": 1001, "value": null,
  "message": "Withdraw request received. Waiting for admin approval. …" }
```

`200` (not an error) so the upstream app treats it as received. Header `X-Withdraw-Gate: queued`.

**Beacon (`POST /api/withdrawIntent.php`).** `{amount, cardHint|card}` posted just before the encrypted submit. Patches the newest amount-less `Pending` (≤10 min) or creates a `fromIntent:true` placeholder. `401` when anonymous.

**Operator side.** `/admin/withdrawals` lists `Pending/Approved/Rejected` with a nav badge count, session hint, amount, card hint, age, and approve/reject. Balance and report shims subtract active holds immediately, so the member sees the hold before the admin decides. The member retries manually after approval.

---

## 4. Game

Custom home grid + in-app shell over upstream game relays. Catalogue, launch URLs, and sessions are upstream; presentation and switches are local.

**Catalogue (`mobile/assets/home.js` through `PXAPI`):**

- Tabs: `গরম (all)` + `RNG/LIVE/PVP/SPORTS/FISH/ELOTT/ESPORTS`, filtered by panel `types` — disabled tabs never render, `গরম` always stays.
- Provider filter + name search are **backend** filters on `GET /wps/relay/GCSGAME_gameList` (`vassalage`, `gameName`), fed by `GET …/GCSGAME_newGameVendor` lanes. Vendor-off in the panel removes both grid tiles and filter chips. Hidden node IDs drop client-side before paint.
- Pagination uses panel `page_size` (4–120, default 24) with skeleton tiles, 4x retry, and queued filter-changes. Winner board (`GET …/GCSGAME_getRankList`, 50 rows), banners (`CCSFE_getListAnnouncements`), and footer vendor strip (24-mark cap) load alongside.
- Search quirk worth knowing: `language` is omitted from `gameList` whenever `gameName` is present — sending both makes the relay return empty even for matching providers.

**Launch (exactly once per tap):**

- `openGame()` reads `gameSettings.in_app`. Default → `/m/game?node=&vendor=&gtype=&name=` (identity handoff; the shell calls the relay). `in_app:false` → direct `location.href = <vendor URL>`.
- `PXAPI.launchGame` (`GET /wps/game/launchGame`) defaults `launchMode:'GLS'` (missing = 500 `#727`), `accountType:1` (real money; `0` = free play), `language:<panel EN>` (missing = Chinese vendor default). `gameUrl()` reads `value.content.game_url` plus legacy shapes.
- `mobile/game.html` retries transient (no-status/5xx) 3x with backoff, validates `https?`, frames the vendor full-screen behind its own bar with an always-present **Back** (same-origin `history.back()` else panel `back_url`) + reload, Try-again/Back on failure, and a 12s slow-frame notice. Business refusals (open round, maintenance) surface as-is.

**Control (`/admin/games` → `GET /api/gameSettings.php`, `no-store`):** master on/off, language, in-app + back path (same-origin enforced), page size, category map, vendor map (live relay ~84 codes; full code list posts so off stays off; `extra_vendors` for offline adds), hidden IDs. Missing code = enabled, so fresh installs offer everything. `config.js` holds `PX_MERCHANT='go44bdtf5'` and must match the active upstream.

---

## 5. Features

**Edge proxy**

- Reverse proxy with disk cache, fastest-first mirrors, health-aware degraded mode, and single-flight fills.
- Brand rewrite (`go444` + `lottogamez/1333bk/1333bet/BigAceWin/lotto` → `BBC99`), URL/domain-aware, auth/CDN/Firebase keys skipped.
- Invite-domain repair, `support.bbc99.bet` consolidation, APK/IPA block, affiliate-redirect switch, `domainRoute` + `checkIfAppDomain` trap neutralizers, asset-HTML mismatch 404s.

**Custom frontend**

- Own home (categories, provider filter + search, winners, banners), login + register (captcha, referral prefill, no OTP), game shell with Back.
- `PXAPI`: Merchant context, token persistence (`token`/`MC_SESSION_INFO`/`login`), device UUID, RSA/DES handshake (`Encryption/X-Digest/X-RSA`), stale-401 guest retry, `reg_info` scrub.

**Deposit desk**

- 6 methods (bKash/Nagad + Send-Money variants, Rocket, USDT), per-wallet channels with `[min,max]`, round-robin rotation, 10-min per-order pages, TrxID flow, single-view burn, ETag-cached landing.

**Withdraw gate**

- Plaintext intent + encrypted-submit hold, per-session single-use approvals, 24h expiry, 60s de-dupe, optimistic balance holds, badge-count admin queue.

**Operator panel (`/admin`)**

- Dashboard, titles/logo/favicon/app-name, banners, marquee, games, voucher & payment methods, pay settings, withdrawals, users, settings, tools — all atomic JSON writes, live next load.
- Installer (`/setup`) with upstream test + lock; mirror benchmark (`upstreams.php`) measured from the server.

---

## 6. Getting started

Requirements: PHP `>= 8.1` (`curl`, `json`, `mbstring`); writable `Proxy/cache` + `Proxy/data`; `Authorization` must reach PHP (`CGIPassAuth On` already set).

```bash
git clone https://github.com/asifbadda/go444.git
cd go444
# dev — ALWAYS with the router:
php -S 127.0.0.1:8000 router.php
# production-style (Procfile):
# cd Proxy && php -S 0.0.0.0:$PORT router.php
```

1. Open `/setup` — upstream, UA, cache TTL (`3600` prod, `0` = uncached debug), affiliate-redirect switch, brand pair, admin key (long random), admin user + 8-char password, cookie name. Saving writes `Proxy/config.php` and locks the installer (`?key=` to reopen).
2. Open `/admin`, configure titles, logo, payments, games, withdrawals.
3. Optional mirrors: `/Proxy/upstreams.php?key=<key>&mirrors=https://a,https://b&save=1` or `php Proxy/upstreams.php https://a https://b`.

---

## 7. Local API reference (`/api/*`)

JSON, `Access-Control-Allow-Origin: *`, `204` on `OPTIONS`. `Proxy/router.php` executes existing `Proxy/api/*.php`, else `404 {"error":"Not found"}`.

| Endpoint | Call | Success | Errors |
|---|---|---|---|
| `POST /api/createOrder.php` | `{method, amount, channel, accountNumber?}` | `{success, trackingNumber, expiresAt}` | `405` · `400` missing/invalid/disabled/unknown-channel/out-of-range |
| `GET /api/getOrder.php?trackingNumber=` | (`tracking=` alias) | full order view (platform, channel, currency, wallet, status, timestamps, payer, TrxID) | `400` · `404` |
| `GET /api/getOrderStatus.php?trackingNumber=` | | `{Id, TrackingNumber, OrderStatusId}` (`1/2/3/4` = WaitingConfirm/Confirmed/Expired/Failed) | `400` · `404` |
| `POST /api/submitTransaction.php` | `{trackingNumber, payerAccount?, trxId\|transferCode?}` JSON or form | `{success, message, trackingNumber, status:"Confirmed"}` | `405/400/404` · `{error:"Order is not pending"}` |
| `POST /api/consumeOrder.php` | `{trackingNumber}` | `{success, trackingNumber}` (burns link) | `405/400/404` |
| `GET /api/getSetting.php` | | `{Country, EnableReturnAmount:false, EnableStoreMode:false, Id:1, IsTest, Language, PlatformName, TimeZone:6, Currency, BrandName}` | — |
| `GET /api/gameSettings.php` | `no-store` | `{success, games:{enabled, language, in_app, back_url, page_size, types, vendors, hidden, updated}}` | — |
| `POST /api/withdrawIntent.php` | `{amount, cardHint\|card}` logged-in | `{success, patched, id}` | `405` · `401` |

---

## 8. Upstream API via `PXAPI` (`/wps/*`)

Same-origin, proxied. Every call sends one `Merchant` (`window.PX_MERCHANT` > meta > localStorage; `go44bdtf5`), `Device: web`, `Language`, `Authorization` when logged in, `X-Real-UA`. Encrypted calls (`login`, `register*`) use proxied `/js/encrypt.js` → `reRsaV2()` → `{value: DES}` + `Encryption/X-Digest/X-RSA`.

| `PXAPI.*` | Upstream | Notes |
|---|---|---|
| `login` / `loginDeviceId` | `POST /wps/session/login` | `{username(=mobile), password, type:'username', loginDeviceId}`; persists `value.token` |
| `logout` | `POST /wps/session/logout` | token cleared even on failure |
| `register`, `registerMobile`, `registerAuto` | `PUT /wps/member/register…` | `{mobile, password, smsCode, inviteCode?}` + deviceId |
| `registerSetting`, `countryCode`, `sendSms`, `sendLoginSms`, `captcha(+Image)`, `captchaGeetest` | system/verification/captcha | captcha `no-store`, image → data-URL |
| `memberInfo`, `balance` | member info / funds | |
| `gameList` / `hotGames` / `gameVendors` / `gameMenus`+`gameTypes` / `winnerBoard` | `GCSGAME_*` relays | list is GET-query; menus map type→vendors |
| `launchGame` + `gameUrl()` | `GET /wps/game/launchGame` | defaults `GLS/1/<panel lang>`; URL from `content.game_url` et al |
| `announcements` | `CCSFE_getListAnnouncements` | banners source |

Stale stored `401` → token dropped + one guest retry. Example: `await PXAPI.login({mobile, password})`, `await PXAPI.loadSettings()`, `PXAPI.gameList({merchant: PX_MERCHANT, platform:'WEB', gameType:'RNG', pageNo:1, pageSize:24})`.

---

## 9. Admin panel guide

`/admin` → `handleAdmin()` (`panel.php`). Username + `password_verify`, 10-try throttle, session regen, per-form CSRF, `?key=` recovery, strangers redirected home. `config.php`/`panel.php`/`includes/` web-denied.

| Group | Section | Controls |
|---|---|---|
| Overview | Dashboard | health, quick actions, cache size + purge |
| Appearance | Titles / Logo / Favicon / App name | `web/mobile/app_name`; uploads or URL fetch → `/images/brand/` (≤3 MB, `?v=`) |
| Content | Banners | `[{image,link,title,active}]` into game JSON |
|  | Marquee | on/off, `[{text,link}]`, `bg/color/speed` |
|  | Games | on/off, language, in-app + back path, page size, categories, vendors, hidden IDs |
|  | Voucher & Payments | `enabled/path/redirect_url/amounts/methods` + wallets/channels editor |
| Payments | Pay Settings | platform/brand/currency/timezone/language/test |
|  | Withdrawals | pending badge, approve/reject, consume tracking |
| Access | Users | add / delete / reset |
| System | Settings | upstream, brand pair, TTL, UA, affiliate switch |
|  | Tools | cache purge, mirror state |

---

## 10. Frontend guide (`mobile/`)

| File | Route | Role |
|---|---|---|
| `index.html` | `/`, `/home`, `/m`, `/m/home`… | home grid, filters, winners, banners |
| `login.html` | `/m/login` | login + captcha; authed `replace()`s home |
| `register.html` | `/m/register` | referral prefilled, captcha only |
| `game.html` | `/m/game` | frame + Back/reload/retry |
| `404.html` | reserved | ready if needed |
| `assets/api.js` | — | `PXAPI`, only backend wiring |
| `assets/app.js` / `home.js` | — | auth forms / home behaviour |
| `assets/config.js` | — | `PX_MERCHANT`, `PX_GAME_GROUPS` (keep in step with panel types) |
| `assets/*.css`, `img/` | — | self-contained styles + local icons |

No upstream bundles referenced (except Material Symbols codepoints for pills). `/m/assets/*` revalidates via ETag — still bump `?v=` after CSS edits for browsers holding the old `immutable` copy. Sessions are shared: our login writes `token`/`MC_SESSION_INFO` exactly as upstream reads.

---

## 11. Brand and MFS logos

- **MFS icons (tracked):** `images/brand/mfs_bkash.svg`, `mfs_bkashsm.svg`, `mfs_nagad.svg`, `mfs_nagadsm.svg`, `mfs_rocket.webp`, `mfs_usdt.svg` → referenced from `payment-methods.json` as `/images/brand/<file>?v=<mtime>`.
- **Site logo/favicon (configured):** `content.json → logo.url / favicon.url` (+ `settings.json` overrides). Prefer `/admin` → Logo/Favicon (upload or URL fetch, type/size-checked, old variant cleared, `?v=` appended) over hand-copying.
- **Frontend/voucher copies:** `mobile/assets/img/`, `voucherCenter/<METHOD>/`, `Proxy/images/{banks,icons}/` (legacy paths).

---

## 12. Configuration reference

`Proxy/config.php` (via `/setup`): `upstream`, `brand_from`/`brand_to`, `cache_ttl`, `user_agent`, `referral_code` (`ggp0537`, posted by the register form — never seeded into storage), `referral_affiliate_code`, `reg_mobile_pattern` (`^01[3-9]\d{8}$`), `reg_username_pattern`, `disable_affiliate_redirect`, `admin{key,user,pass_hash,cookie}`. Engine constants: `UPSTREAM_FAIL_TTL=120`, `FAILOVER_BUDGET=10`, `SLOW_MS=2000`, `DEGRADED_TTL=30`, `REVALIDATE_1_IN=20`, `FILL_WAIT_MS=2500`, `BRAND_SKIP_KEYS` (domains/auth/Firebase never rewritten).

---

## 13. Routing reference

| Request | Serves | Notes |
|---|---|---|
| `/`, `/index(.html\|.php)`, `/home`, `/m*`, `/m/home` | `mobile/index.html` | raw custom home |
| `/m/login`, `/m/register` | `mobile/*.html` | raw auth |
| `/m/game` | `mobile/game.html` | relay called once, in shell |
| `/m/account` → member home, `/m/deposit` → voucher | `302` | legacy shortcuts |
| `/m/assets/*`, `/favicon.ico`, `/sw.js`(+`/m/`) | local | ETag / icon / worker-killer |
| `/admin*`, `/setup*`, `/api/*` | panel / installer / local API | installer locked after run |
| `/voucherCenter*`, configurable voucher path | `voucherCenter/` | only when enabled |
| `*.apk/*.ipa` | `404` | browser install only |
| real non-PHP file | as-is | css/images/Pay.html/robots… |
| everything else | upstream page | full proxy pipeline |
| `/Proxy/*` | `301 /*` | prefix hidden |

---

## 14. Deployment

- **Apache:** docroot = repo root; `.htaccess` covers `/admin`, `/setup`, `/api/*`, real files, `Proxy/index.php` fallback + `CGIPassAuth`.
- **nginx:** `nginx.conf` (`/admin`, `/setup`, `/VoucherCenter`, `/ → index.php`, `*.php → php-fpm`); forward `Authorization/Merchant/Device/Language/Encryption/X-Digest/X-RSA`.
- **PaaS:** `Procfile`: `cd Proxy && php -S 0.0.0.0:$PORT router.php`. Extensions `curl/json/mbstring`; `Proxy/cache`, `Proxy/data` writable.
- After cloning: fresh `/setup`, real password, `3600` TTL, rank mirrors, keep `data/*.json` (phones/TrxIDs) out of backups.

---

## 15. Security

Secrets = `config.php` + `users.json` — never commit/log/screenshot; rotate after cloning. Admin cookies `HttpOnly`/`SameSite=Lax` (`Secure` on HTTPS) + CSRF + throttling + `password_hash`. `data/`, `config.php`, `panel.php`, `includes/` web-denied; `..`/`\` rejected pre-disk. Authed JSON/errors/auth = `private,no-store`. SSL verify on; DNS pinned per fetch; `back_url` same-origin; uploads type-sniffed. Captcha `no-store` + timestamped; approvals single-use + session-bound; pay links single-view.

---

## 16. Storage and data files

`Proxy/data/` (atomic `.tmp` + `rename`, `flock` rotation; `store.php` is the only writer): `content.json` (banners/marquee/titles/logo/favicon/voucher/games), `settings.json`, `payment-methods.json`, `orders.json` (`{orders[], nextId}`), `payment-rotation.json`, `withdraw-approvals.json` (`{items[], nextId}`), `users.json`, `games-vendors.json`. `Proxy/cache/` = assets + `upstream-health.json` + `upstream-fail.json` (safe to delete).

---

## 17. Tools and maintenance

- Mirrors from the server: `php Proxy/upstreams.php <urls…>` or `/Proxy/upstreams.php?key=…[&mirrors=a,b][&save=1]` (TTFB table, refuses to save when nothing usable).
- Cache via `/admin` → Tools or by deleting `Proxy/cache/*`.
- `php tools/audit_functions.php Proxy/index.php […]` finds called-but-undefined helpers; `render_admin_preview.php` previews panel screens.
- Watch PHP `error_log` (upstream errors, degraded transitions, sweep budgets). Background: `problem-to-fix.md` (fixed E2E log), `plan-for-member-home.md` (Option-B member-home plan, unbuilt).

---

## 18. Troubleshooting

| Symptom | Fix |
|---|---|
| `/setup` redirects home | append `?key=<admin key>` |
| Login `400 request_body_required` | forward `X-Digest`/`X-RSA` (fixed in `fetchFromUpstream`) |
| Login → instant guest | persist `value.token`, check `CGIPassAuth` |
| Games `#727` 500 | `launchMode:'GLS'` default in `PXAPI.launchGame` |
| Games in Chinese | default `language` to panel `EN` |
| No Back in game | route launches through `/m/game` |
| Bounced to foreign domain | scrub (never seed) `reg_info` referral keys |
| Blank proxied pages | base from `REQUEST_URI`, reject `/` |
| Upstream worker 503 shells | stub every `…/sw.js` |
| Stale captcha / 401 feeds | `no-store` captcha; drop + guest-retry token |
| Dead mirror stalls site | re-rank via `upstreams.php`, check `upstream-fail.json` |
| Voucher `404` | enable voucher; path must contain `voucherCenter` |
| Wrong wallet shown | intentional rotation — pin via `accountNumber` |
| Stale logos/CSS | re-save in `/admin` (new `?v=`), hard-reload |

---

## 19. FAQ

**Database?** No — `Proxy/data/*.json` is the DB. Back it up like one. **Passwords stored?** Only admin hashes; member credentials pass through encrypted. **Subfolder install?** Yes, base detection handles it. **Rebrand?** Settings + Appearance/Content — domains/auth never change. **New wallet?** Voucher & Payments → method → wallet + channel `[min,max]`; rotation is automatic. **Withdraw path to upstream?** One approval = one retried POST, everything else stays local `Pending`. **Help?** Member issues → `support.bbc99.bet`; repo issues → route + `X-Proxy`/`X-Withdraw-Gate` headers + redacted `error_log`.
