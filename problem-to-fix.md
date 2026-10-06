# Problems to fix

Source: browser-only end-to-end check of the current build (dev server on
`127.0.0.1:8941`), driven through the real UI at 414x896 and 1280x860, while
logged in with a real account (`01336710020`) — every finding below was observed
by clicking the real control or reading the real response in the browser.

Legend: **P1** blocks a user / a route that must work · **P2** control does
nothing at all · **P3** polish, data correctness, consistency.

---

## Architecture (settled)

The proxy mirrors the upstream everywhere — same HTML, same backend — and only
three pages are ours:

| Route | Served by |
|---|---|
| `/`, `/index`, `/index.html`, `/home`, `/m`, `/m/index(.html)`, `/m/home` | `mobile/index.html` (our home) |
| `/m/login`, `/m/register` | `mobile/login.html`, `mobile/register.html` |
| everything else (`/m/member/home`, `/m/voucherCenter`, `/m/withdraw`, `/m/activity`, `/m/gameCenter`, `/m/inviteFriends`, records, …) | the **upstream**, proxied with the normal rewriting/injection pipeline |

The custom pages are the entry points; every control on them that belongs to the
site proper links straight into the proxied upstream page. The login session is
shared: our `PXAPI` writes `token` + `MC_SESSION_INFO` in sessionStorage in the
shape the upstream bundle reads, so a member who logs in on our page is already
signed in when the upstream SPA boots.

---

## Fixed in this session

| # | Problem | Status |
|---|---|---|
| 0.1 | Upstream HTML (go444 SPA shell) was served to any client whose `Accept` was a wildcard or absent. | **Superseded** — with the split above, non-custom routes are *meant* to render upstream HTML, so the refusal guard was removed rather than tightened. Our own routes are answered by `$localPages` before any fetch. |
| 0.2 | Login was impossible: upstream demands an image captcha and our page rendered none. | **Fixed** — captcha wired into `mobile/login.html` + `mobile/assets/app.js`; verified by real logins. |
| 0.3 | `/wps/captcha` was cached `public, max-age=86400, immutable`, so a browser reused one image for a day. | **Fixed** — the path is per-visitor traffic (`private, no-store`, `Vary: Cookie`). |
| 0.4 | **Every proxied page was broken.** `dirname()` is platform-aware, so on Windows `dirname('/index.php')` returns `"\"`, not `"/"`. `$base` became a backslash, and the base shim was injected as the invalid literal `var b="\";` — a `SyntaxError` that killed the whole upstream bundle (blank page). | **Fixed** — `$base` is derived by hand (no `dirname()`) and validated: `/index.php → ''`, `/Proxy/index.php → '/Proxy'`. The shim is no longer injected at the document root. |
| 0.5 | **Upstream URLs lost their path.** Normalising that backslash to `/` made `$base === '/'`, and the base-stripping step then cut the leading slash off every request: the proxy asked for `www.go444.iom/member/home` and every proxied page answered 502. | **Fixed** — same change as 0.4; `/` is rejected as a base. |
| 0.6 | **Visitors were bounced off the site.** The referral shim planted our own code (`ggp0537`) into `localStorage.reg_info` on every proxied page. The upstream reads that as "arrived through an affiliate" and answers by redirecting to *that* affiliate's landing page on a foreign domain (observed: `https://<other-domain>/register?r=ggp0537`). | **Fixed** — the shim now scrubs `referralCode`/`affiliateCode`/`inviteCode` out of `reg_info` instead of writing one, and `mobile/assets/api.js` clears any value left by an earlier build. Our register form posts `referralCode` in its own payload, so attribution is unaffected. (There were **two** copies of this shim; both are fixed.) |
| 0.7 | **The upstream's service worker was live on our origin**, registered at `/m/sw.js?env=production&merchant=…` with scope `/m/`. It intercepted navigations and answered `/m/home` with **503**, leaving our own page blank. | **Fixed** — the neutraliser now answers *every* `…/sw.js` path, not just `/sw.js`, so the upstream worker unregisters itself and drops its caches. |
| 0.8 | The slide-in menu was painted over the home page on first load: `.px-menu { display: flex }` beats the user-agent `[hidden] { display: none }` rule. | **Fixed** — explicit `.px-menu[hidden], .px-menu-scrim[hidden] { display: none }`. |
| 0.9 | The upstream SPA is a client-side router, so when it navigated onto one of our routes (`/m/home` in particular) it only rewrote the address bar — the visitor read upstream markup under our URL, and a refresh later swapped the page under them. | **Fixed** — proxied pages get a history patch that turns a `pushState`/`replaceState` onto `/`, `/m/home`, `/m/login`, `/m/register`, … into a real document load. |
| 0.10 | `mobile/404.html` was served by the removed HTML-refusal guard and is no longer reachable: unknown routes are the upstream's now, and its SPA redirects them to `/m/home`. | **Noted** — file kept and brought into the new auth design so it is ready if a route ever needs it, but nothing serves it today. |
| 0.11 | **Login stopped working.** `fetchFromUpstream()` in `Proxy/index.php` forwarded the `Encryption` header but dropped `X-Digest`/`X-RSA`, so every `POST /wps/session/login` reached the backend with an encrypted body it could not check and answered `400 request_body_required` ("System error"). The client payload was valid the whole time. | **Fixed** — the proxy now forwards `X-Digest` and `X-RSA` alongside `Encryption`. Verified end to end with the real account: login lands on `/m/home`, token + `MC_SESSION_INFO` set, header shows *Emon · ৳0.00*, and `/wps/member/info` returns 200 instead of 401. |
| 0.12 | **Every game launch failed.** The grid asked `/wps/game/launchGame` for `gameType`, `vassalage`, `gameId`, `nodeId`, `platform` — all of them except `launchMode`, which the backend requires on every launch. Without it the relay answered HTTP 500 `#727` "system busy" for every game, so a tap looked broken while the backend was healthy the whole time. The play URL was also read from the wrong place: the relay nests it under `value.content.game_url`, while the client looked only at `value.url/gameUrl/link/launchUrl`. | **Fixed** — `PXAPI.launchGame` now defaults `launchMode:"GLS"` and `accountType:1` (real-money wallet; `0` is the free-play wallet), and a new `PXAPI.gameUrl()` reads the URL from any shape, including `value.content.game_url`. Verified in the browser with the real account: tapping supper-ace on `/m/home` opens the vendor's live game (`wbgame.jackpotpulse888.com/…`, JILI scene loads). |
| 0.13 | **Games opened in Chinese.** The launch request carried no `language`, so vendors fell back to their own default — JILI opened `lang=zh-CN`. The relay forwards `language` straight to the vendor, which localises its game and loading screen from it. | **Fixed** — `PXAPI.launchGame` now defaults `language:"EN"` (callers can still override). Verified: JILI answers `lang=en-US` (was `zh-CN`), SPB `lang=en`, PG `tcgLanguage=EN`/`l=en`, and the PG loading screen reads in English. |
| 0.14 | **A launched game was a one-way door.** Tapping a game sent the tab straight to the vendor, and whether the player could get back depended on that vendor happening to draw its own back control — several do not, and the relay ignores a `backUrl` passed to it. | **Fixed** — games now open in an in-app window, `/m/game`, which frames the game full-screen behind our own bar with an always-present **Back** button (plus reload). It launches the game itself, so the relay is still called exactly once — launching in the grid first would trip `#734 "the previous game has not finished"`. Back returns to the referring page when it is ours, otherwise to the configured target; a launch failure offers Try again / Back instead of a blank frame, and a 5xx is retried twice before the error is shown. Verified: the bar, title, Back, retry and error states render, and the MG and Spribe games load inside the frame. |
| 0.15 | **Nothing about games was controllable.** Which categories and vendors the site offered, what language a game opened in, how many games a page held, and whether a given game appeared at all were all hardcoded in the frontend. | **Fixed** — a new **Games** section in the admin panel (`/admin/games`) owns all of it: master on/off, default game language, in-app window + Back target, games per page, per-category and per-vendor switches, hidden game IDs, and extra vendor codes. Vendors are fetched **live** from the game relay (84 known) so the switches describe the real catalogue. The frontend reads it from `/api/gameSettings.php` and falls back to the previous behaviour if that is unreachable. Verified end to end: disabling a vendor removed it from the provider filter (65→64 chips), disabling a category removed its tab (8→7), a hidden ID dropped its tile (24→23), and re-enabling restored each. |
| 0.15a | A vendor toggled **off** came back on after saving: a browser only posts *checked* boxes, so "switched off" was indistinguishable from "never rendered". | **Fixed** — the full vendor code list is posted alongside the boxes and the save treats a missing box as off. Verified: `2J` stored `false` while `JL` stayed `true`. |
| 0.15b | The panel offered a **Cockfight** category switch that controlled nothing — the home page's tab list never had one. | **Fixed** — the panel's category list is now exactly the tabs the page draws (7 + “গরম”), so every switch maps to something visible. |

---

## P1 — blocks the user

### 1. There is no way to log out — **Fixed**
* **Was:** no page carried a Log out action, so the handler in
  `mobile/assets/app.js` never attached.
* **Now:** the home hamburger menu carries a Log out item, shown only with a
  session. Verified in the browser: it fires `POST /wps/session/logout`, clears
  `token` / `MC_SESSION_INFO` / `login`, and lands on `/m/login`.

### 2. Deposit / Withdraw / ডিপোজিট / সদস্যরা all bounced a logged-in user to login — **Fixed**
* **Was:** all four controls hardcoded `/m/login`.
* **Now:** they resolve at click time against the hydrated session and go to the
  real upstream pages — deposit `/m/voucherCenter`, withdraw `/m/withdraw`,
  account `/m/member/home` — and only guests are sent to `/m/login`. Verified in
  the browser: a member reaches the upstream member centre (VIP0, 01336710020,
  Nickname Emon), the upstream deposit page (bKash/Nagad methods, amounts) and
  the upstream withdraw page; a guest tapping ডিপোজিট gets `/m/login`.

### 3. `/m/login` and `/m/register` did not redirect a logged-in visitor — **Fixed**
* **Now:** both pages ask `/wps/member/info` and `location.replace('/m/home')`
  once a member identity is confirmed.

### 4. Signup asked for something upstream does not have: an OTP — **Fixed**
* **Now:** the OTP block and the send-code handler are gone; the captcha is the
  only verification, matching the upstream's own signup page.

### 5. The referral code was sent under a name upstream does not accept — **Fixed**
* **Now:** the field is `referralCode` and the payload is
  `{ mobile, password, captcha, referralCode }`.

### 6. A failed game launch told the user nothing — **Fixed**
* **Now:** both the empty payload and the rejection are toasted with the
  upstream's own message. (The `#727` failure itself is upstream-side.)

### 7. Password rules on signup did not match the backend — **Fixed**
* **Now:** 6-12 characters and `^[a-zA-Z0-9]+$` are enforced before submitting,
  with `maxlength="12"` on the field.

---

## P2 — controls that do nothing

### 8. Bottom nav: শেয়ার and প্রমোশন are dead — **Fixed**
* **Now:** প্রমোশন navigates to the upstream promotions page (`/m/activity`) —
  verified in the browser, it renders the upstream's own promotion content.
  শেয়ার shares the site link via the Web Share API, falling back to the
  clipboard and then `execCommand`.

### 9. Header hamburger does nothing — **Fixed**
* **Now:** it opens a slide-in menu wired to **all** `.header-menu` elements
  (guest and member variants). Its items are the real upstream routes: ক্যাসিনো,
  আমার অ্যাকাউন্ট, ডিপোজিট, উইথড্র, আমন্ত্রণ, প্রমোশন, গেম রেকর্ড, লেনদেন,
  plus share and (with a session) log out.

### 10. Download-bar store badges do nothing — **Fixed**
* **Now:** the real download bar is addressed by id (`#app-download-bar`) and the
  `.download-item` handler is delegated from `document`, so a badge works wherever
  it sits in the markup.

### 11. Login page: Telegram / Forgot password / Remember me — **Fixed**
* **Now:** "Remember" persists the token in `localStorage` (`px_remember_token`)
  and `app.js` seeds `sessionStorage` from it on the next visit, so a member
  stays signed in across tabs **and** across proxied upstream pages (the upstream
  bundle reads `sessionStorage`). `PXAPI.logout()` clears both — `setToken('')`
  now removes the remembered copy too.
  "Forgot password?" points at the upstream's own `/m/forget`, which the proxy
  renders with real content (verified: *"Please retrieve your password in the
  following ways"*).
* **Changed with the new UI:** the "Continue with Telegram" button is gone. The
  reference design shows Facebook and Google circles instead, and neither has a
  working web login upstream — its Facebook login lives in the native app only
  and answers *"coming soon"* on the web, and there is no Google login at all.
  The circles are shown for parity and say so when tapped rather than sitting
  inert.

---

## P3 — polish / consistency

### 12. Footer and banner affordances are not interactive — **Open**
* Social icons (Facebook / X / YouTube) are `<span>`s, not links; the footer's
  "সাহায্য" and "কীভাবে খেলবেন" are plain text with no `href`; the home banner
  image has no link or handler. The upstream has pages for these
  (`/m/feedback`, `/m/message`, `/m/help`), so the fix is to link them there.

### 13. Empty category says "No games" in English — **Open**
* Lottery (`ELOTT`) and e-sports (`ESPORTS`) return no games for this merchant
  and render the literal string `No games` inside an otherwise Bengali UI.

### 14. Cache-busting tokens disagree across pages — **Open**
* `/m/home` uses `?v=20261002c`, `404.html` uses `20261002`, login and register
  use `20261001` for `app.css`/`config.js`/`api.js`/`app.js`. Harmless today
  (assets are `no-cache, must-revalidate` + ETag) but it makes cache debugging
  guesswork.

### 15. No `color-scheme: dark` — **Open**
* Neither stylesheet declares it, so the browser paints a light scrollbar (and
  light native form controls) on a fully dark UI.

---

## Not ours (upstream-side, recorded so it is not re-diagnosed)

* **Mobile registration is switched off:** `PUT /wps/member/register/mobile`
  answers `#543 function.not.available` ("The feature is under maintenance") for
  every payload shape tried — as shipped, with a `captcha` field, with
  `referralCode`, and with `username` instead of `mobile`. Same code the disabled
  geetest captcha endpoint returns. No account is created and no SMS is sent by
  this endpoint.
* ~~**Game launch fails server-side**~~ **Re-diagnosed as ours, not upstream.**
  The `#727` was the relay rejecting a request that omitted its required
  `launchMode` field — with `launchMode=GLS` the same call answers 200 and a real
  `game_url` (see 0.12). A genuine `#459 http_method_not_support` only appears
  when the endpoint is called with POST; it is a GET relay.
* **Upstream popups are unbranded-unfriendly:** on first visit to a proxied page
  the upstream shows a full-screen “Invite & Get 8,888 FREE” promo (and a
  “Turn on notifications” prompt carrying the GO444 mark). These are its own
  components and arrive on its live site too; they close from inside their own
  overlay. If BBC99 branding must replace them, the images behind them have to be
  rewritten like the rest of the brand payload.
* **Stale copy of this project is running on `127.0.0.1:8000`** (`php -S
  127.0.0.1:8000 -t . Proxy\router.php`) serving the pre-migration 1333bk-era page,
  plus two more servers on `8080`. Worth stopping so only the current build is
  reachable. Note cookies ignore ports, so a session on one port is visible to the
  others.

---

## Verified working end to end (browser, real account `01336710020`)

1. Guest home → real games, winners, banners and vendor footer from the backend.
2. Guest ডিপোজিট → `/m/login` (correct: no session yet).
3. Login with captcha → lands on `/m/home`, header shows *Emon · ৳0.00*.
4. Bottom-nav সদস্যরা → proxied upstream `/m/member/home`, already signed in.
5. Member centre → Deposit → proxied upstream `/m/voucherCenter` (bKash/Nagad).
6. উইথড্র → proxied upstream `/m/withdraw`.
7. প্রমোশন → proxied upstream `/m/activity`.
8. Drawer items (কাসিনো, আমন্ত্রণ, গেম রেকর্ড, লেনদেন) carry their real routes.
9. Log out → `/wps/session/logout`, session cleared, lands on `/m/login`.
10. No visitor is redirected off our domain any more (see 0.6).
