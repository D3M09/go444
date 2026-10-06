# Plan — Custom Member Home (`/m/member/home`) via Option B

Date: 2026-10-06
Status: plan only, no code changed
Account observed: `01336710020` / Nickname `Emon`
Tab: `tab_e3607a29` at `http://127.0.0.1:8942/m/member/home`

## 1. Goal

Replace proxied upstream `/m/member/home` with our own `mobile/member.html`
(same pattern as `m/index.html`), same look-and-feel ownership, all data from
upstream backend through one custom aggregator:

`GET /api/memberSummary.php` (new) → upstream `/wps/*`.

Phase 1 keeps money-movement and record pages proxied (deposit, withdraw,
histories). Only the dashboard shell becomes custom.

## 2. Current upstream behavior (observed live)

Entry: SPA shell `mcMain.8203ab41.chunk.js` + `chunk-vendor / chunk-common /
vendors~notification / goldenEgg / notification / MiniGame / theme-common /
A2HSModal` boots on `/m/member/home`, fires ~35 `/wps/*`, all 200/204.

Header rendered:

> My Account / VIP0 / 01336710020 / Nickname: Emon / Joined: 2026-10-01 /
> ৳ 0.25 / Deposit / Withdrawal / Bank Account

Menu rendered:

> Member Center / My Rewards / Bet History / Profit And Loss / Deposit Record /
> Withdrawal Record / Transaction History / My Account (/m/myAccount/nickName) /
> Security Center / Invite Friends / Internal Message (badge 1) / Suggestion /
> Customer Service / Logout + promo overlay “Invite & Get 8,888 FREE”.

Back button (`#mc-header .return_icon svg.am-icon-left`) does
`history.replaceState(..., "/m/home")`. Our shim (`Proxy/index.php`
`injectCombinedShims`) now blanks + `location.replace("/m/index.html")`.

## 3. API → UI map (verified bodies)

| UI | API | Key fields |
|---|---|---|
| Name / VIP / account / joined | `GET /wps/member/info` | `value.clubLabel.labelName=VIP0`, `nickname=Emon`, `account=01336710020`, `regDate=1790855866000`, `mobile=0133671****` |
| Balance `0.25 ৳` | `GET /wps/v2/wallets/balance?typeId=0` | `value.sumBalance=0.25`, `balance[accountTypeId=2].availBalance=0.25`, `currency=BDT`, `currencySymbol=৳` |
| VIP progress | `GET /wps/relay/MCSFE_getPlayerRankProgress` | `playerRankName=VIP0`, `monthlyTurnover actual 745.5 / expected 1000`, `totalTurnover same` |
| Invite | `GET /wps/relay/MCSFE_getReferralDetails?autoGenerate=true` | `referralCode=awa6684`, `totalInvitations=0`, `promotionDomains=[go444...]` |
| Message badge `1` | `GET /wps/relay/EMFE_getInboxUnreadCount` → `{"value":1}` + `GET EMFE_getInbox?page=1&size=1` | badge count |
| Deposit enabled | `GET /wps/relay/MCSFE_getDepositSettings` | `allowDeposit=Y`, `totalDepositSuccessCount=1`, `depositInfo=24/24` |
| Withdraw limits | `GET /wps/relay/MCSFE_getWithdrawSettings?groupId=0` | `minimumAmount=100`, `maximumAmount=50000`, eWallet 100–25000 |
| Rewards / popups | `GET MCSFE_getAvailablePromotions`, `PROMOFE_getTicketGlobalConfig/getClaimTicketList/getDiscountTicketList/getUnappliedExtraRewardClaimList/getAchievementSettingList/getParticipableQuestList/recordCustomerHeartbeatDaily`, `PROMOFE_getRankBoardList`, `CCSFE_getListAnnouncements?AFTER_LOGIN`, `MCSFE_getListAnnouncements?types=A`, `PROMOFE_feLayoutPromotion` | promo overlay, My Rewards |
| Avatar | `GET MCSFE_getPlayerAvatar` | `{"avatar":"0","avatarType":"STATIC"}` |
| CS | `GET WPSCORE_getCustomerServiceScript`, `GET CSP_ImUrl` → `https://socket.7243249.com` | Customer Service link |
| System only (do not replicate) | `v2/system/status`, `system/settings/consolidated`, `system/country`, `system/helpCenter`, `MCSFE_getCustomerKycMerchantSetting`, `WPSCORE_checkIfAppDomain` (neutralized), `POST system/collect → 204` | boot / analytics |
| Menu rows | links only, no fetch on dashboard | Bet/Deposit/Withdraw/Transaction History, P&L, Security, Suggestion fetch on their own pages |

Full request list captured in browser network log (`/wps/` × 35, all 200/204).

## 4. Architecture (Option B)

```
member.html + member.js
  → GET /api/memberSummary.php (same-origin)
    → PHP fans out to upstream (server-side):
      member/info, wallets/balance,
      MCSFE_getPlayerRankProgress,
      MCSFE_getReferralDetails,
      EMFE_getInboxUnreadCount,
      MCSFE_getDepositSettings,
      MCSFE_getWithdrawSettings
    → single JSON to browser
  → lazy direct PXAPI calls only when opened:
      promos, inbox list, announcements
```

Why aggregator, not 7 direct calls:

- 1 round-trip, hides relay names, one auth check, one `partial:true`
  fallback, server can apply withdraw-hold subtraction before paint.

## 5. Changes

### 5.1 `Proxy/api/memberSummary.php` (new)

- Route: auto-served by `Proxy/router.php:81-85` (`/api/*.php`), no router edit.
- Auth: build `requestSessionKey()` from `Cookie + Authorization +
  Encryption + X-Gateway-Version` (same as `Proxy/index.php:2885`,
  `api/withdrawIntent.php:31-35`). Empty → `401 {needLogin:true}`.
- Forward headers per upstream call: `Authorization, Merchant, Device,
  Language, Cookie, User-Agent` (mirror `fetchFromUpstream:911-930`).
  Include `X-Digest/X-RSA/Encryption` passthrough if present.
- Upstream base: `activeUpstream()` from `upstreams.json` + `UPSTREAM`
  (`config.php: upstream=https://www.go444.io`).
- cURL: 6s timeout, `GET` only, 1 retry on 5xx, no retry on timeout
  (protects PHP pool). Parallel via `curl_multi` or sequential (7 × <500ms
  typical).
- Apply withdraw hold: subtract `withdraw_active_hold_sum(sessionKey)`
  from `sumBalance/availBalance` (same as `applyWithdrawBalance`).
- Response headers: `Content-Type: application/json`,
  `Cache-Control: private, no-store, must-revalidate`, `Vary: Cookie`,
  `X-Proxy: true`. Never write disk cache. Optional 10s APCu per-session.
- Shape:

```json
{
  "success": true, "partial": false,
  "member": {"nickname":"Emon","account":"01336710020","mobile":"0133671****","vip":"VIP0","joined":"2026-10-01"},
  "balance": {"sum":0.25,"avail":0.25,"currency":"BDT","symbol":"৳"},
  "vip": {"current":"VIP0","next":"VIP1","turnoverActual":745.5,"turnoverExpected":1000},
  "referral": {"code":"awa6684","invites":0},
  "unread": 1,
  "deposit": {"allowed":true},
  "withdraw": {"min":100,"max":50000}
}
```

- Errors: upstream 401 → `401`; timeout/5xx on one leg → `partial:true`
  + succeeded legs; all fail → `502`.

### 5.2 `mobile/member.html` + `mobile/assets/member.js` + `member.css` (new)

- Clone `mobile/index.html` shell (header, bottom nav, toast, hamburger).
  Sections: profile header (avatar, VIP pill, account, joined), balance
  card (symbol, amount, Deposit/Withdraw/Bank buttons), quick grid, menu
  list with unread badge, Logout.
- `member.js`: on load → `PXAPI.memberSummary()` → paint; on `needLogin`
  → `location.href=/m/login`; buttons → `/m/voucherCenter` (deposit,
  proxied), `/m/withdraw` (proxied, keeps `withdrawGate.php`), bank →
  upstream bank page proxied; records/P&L/Security/Message/Invite rows →
  existing upstream routes (phase 1 proxied); Logout → `PXAPI.logout()`.
- Add `PXAPI.memberSummary()` wrapper in `mobile/assets/api.js:411`
  alongside `memberInfo/balance`, with stale-token retry
  (`api.js:382-391`).

### 5.3 `Proxy/index.php` routing (1 line)

```php
'/m/member/home' => 'member.html',
```

in `$localPages` (`:125-140`). Served by `serveLocalPage():3037`
(byte-for-byte, no shims). Remove nothing else. `/m/home` guard stays;
member home is not in `OURS` list so no loop.

## 6. Security / cache notes

- Follow `isAuthTraffic` no-store + `cacheStoreAllowed` (no `Set-Cookie`
  store). Per-session isolation via `cacheFileFor():2923` if any cache added.
- Never log token. `error_log` URL + status only.
- Keep `Merchant=go44bdtf5` (`mobile/assets/config.js:10`) single header
  (`api.js:186`); duplicate Merchant → `400 function.not.available`.

## 7. Test checklist (real account `01336710020`)

1. `php -l` on new PHP + JS smoke.
2. Guest `fetch('/api/memberSummary.php')` → 401.
3. Member → values equal live bodies: `VIP0, 0.25, awa6684, unread 1`.
4. Guest `/m/member/home` → redirect `/m/login`; member paints <2s, no
   upstream chunks in `document.scripts`.
5. Deposit/Withdraw/Bank navigate to proxied upstream pages signed in.
6. Back from member home → `/m/index.html` (existing guard).
7. Kill upstream mirror → `partial:true` still paints.

## 8. Risks / plus

Plus: full design control, no upstream-markup flash, 1 JSON vs 7,
no popup fight, consistent with `m/index.html`.

Risks: must mirror encrypted-header forwarding; VIP/balance field renames
break paint; withdraw/bank/records stay proxied in phase 1 by design;
per-user JSON must never be shared-cached.

## 9. Phase 2 (not in scope)

Customise records, P&L, inbox, invite pages one by one behind same
aggregator once dashboard is stable.
