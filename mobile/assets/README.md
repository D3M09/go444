# Custom frontend (your own HTML)

Your own HTML/CSS/JS for every **page** of the site. The proxy serves these files
raw (no brand rewriting, no injected shims) and never renders an upstream page:
anything else is a backend call or a 404 (see `../404.html`).

| route                                            | file              |
|--------------------------------------------------|-------------------|
| `/`, `/index.html`, `/home`                      | `../index.html`   |
| `/m`, `/m/`, `/m/index.html`, `/m/home`          | `../index.html`   |
| `/m/login`                                       | `../login.html`   |
| `/m/register`                                    | `../register.html`|
| any other page navigation                        | `../404.html` (404) |
| `/m/assets/<name>`                               | files in this dir |

## Files
- `../index.html`   – home (categories, provider filter + name search, games,
  winner board, banners from the backend)
- `../login.html`   – login form
- `../register.html`– registration form (referral field is pre-filled from the
  admin panel's referral-code setting via the `__PX_REFERRAL_CODE__` placeholder)
- `../404.html`     – "no such page" answer for any route without a custom file
- `home.css`  – home page design system, **self-contained** (own icons/colours)
- `app.css`   – login / register design system
- `home.js`   – home behaviour; talks to the backend only through `PXAPI`
- `api.js`    – `PXAPI`: thin client for the go444 backend
- `app.js`    – login / register behaviour
- `config.js` – `PX_MERCHANT` and the game-category list
- `img/`      – local icons: `bkash.png` `nagad.png` `rocket.png` `favicon.png`

Assets here are served at `/m/assets/<name>`, revalidated on every load (ETag),
so an edit shows up on the next reload — no cache-busting needed.

**Still bump the `?v=…` query in the page whenever you change a stylesheet.**
The proxy used to send these files as `immutable, max-age=86400`; a browser
that pulled one during that window keeps it until it expires and will not
revalidate. The symptom is new markup rendering with no rules for it — e.g. a
row of provider chips collapsing to each logo's natural size, so the tiles come
out “some big and some small”.

**No upstream build output is referenced by these pages** — icons are inline
SVG or files in `img/`, so the design survives an upstream rebuild. The one
external request is Google Fonts' *Material Symbols Rounded*, used by the eight
category pills on the home page (`local_fire_department`, `casino`, `live_tv`,
`playing_cards`, `sports_soccer`, `phishing`, `confirmation_number`,
`sports_esports`). The glyphs are written as codepoints rather than ligature
words, so if fonts.googleapis.com is unreachable the pills go empty instead of
spelling "casino" across the menu; everything else on the page still renders.
If you ever want the reference look again, style it here; do not point these
pages at another host's bundle.

## Backend (go444)
Upstream is **https://www.go444.io** and is used as a backend only. These paths
are proxied as usual: `/wps/*`, `/lgw/*`, `/js/*`, `/css/*`, `/common/*` … .
Upstream HTML is never rendered: a browser navigation that would get HTML from
upstream is answered with `../404.html` instead.

Encrypted endpoints (`/wps/session/login`, `/wps/member/register/mobile`) use the
upstream handshake: the page loads `/js/encrypt.js` (proxied byte-for-byte), then
`PXAPI` calls `window.reRsaV2(payload)` → body `{ value: s.DES }` with header
`Encryption: s.RSA`.

### Merchant context
Backend calls require a `Merchant` header. It is set in `config.js` to the go444
whitelabel code **`go44bdtf5`** (from the upstream brand config
`brand:{platform:"WEB",merchant:"go44bdtf5"}`). Always keep it in step with the
upstream configured in `Proxy/config.php`. `PXAPI` also sends `Device: web` and
`Language`, like the upstream app.

Override if needed via `window.PX_MERCHANT`, `<meta name="px-merchant">`, or
`localStorage.px_merchant`.

### Payload fields
Login sends `{ mobile, password }`; registration sends
`{ mobile, password, smsCode, inviteCode? }`. If the backend expects different
field names, adjust the payloads in `app.js` (the endpoints/verbs in `api.js`
already match upstream).

## Local development
From the project root, always start the dev server **with the router**, otherwise
extension paths (`/m/assets/*`, `/wps/*`, `/js/*`) 404 before PHP runs:

```
php -S 127.0.0.1:8000 router.php
```

(`cd Proxy && php -S 127.0.0.1:8000 router.php` also works.)
