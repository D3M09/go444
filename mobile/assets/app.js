/* ==========================================================================
   BBC99 mobile frontend — UI behaviour.
   Network calls are delegated to PXAPI (api.js). Nothing here talks to the
   backend directly.
   ========================================================================== */
(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------- toast ---------- */
  function toast(message, kind) {
    if (!message) return;
    var wrap = $('.toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'toast-wrap';
      document.body.appendChild(wrap);
    }
    var el = document.createElement('div');
    el.className = 'toast' + (kind ? ' toast--' + kind : '');
    el.textContent = message;
    wrap.appendChild(el);
    setTimeout(function () {
      el.style.opacity = '0';
      el.style.transform = 'translateY(6px)';
      el.style.transition = 'opacity .2s, transform .2s';
      setTimeout(function () { el.remove(); }, 220);
    }, 3200);
  }

  function busy(btn, on) {
    if (!btn) return;
    if (on) {
      btn.dataset.label = btn.dataset.label || btn.innerHTML;
      btn.classList.add('is-loading');
      btn.disabled = true;
    } else {
      btn.classList.remove('is-loading');
      btn.disabled = false;
      if (btn.dataset.label) btn.innerHTML = btn.dataset.label;
    }
  }

  function digits(s) { return (s || '').replace(/\D+/g, ''); }

  /* ---------- "Remember me" ----------
     The token itself always lives in sessionStorage (that is the key the
     upstream bundle reads). Remembering the session means keeping a second
     copy in localStorage and seeding sessionStorage from it on the next visit,
     so a member stays signed in across tabs and across proxied upstream pages.
     PXAPI.logout() clears both - see setToken('') in mobile/assets/api.js. */
  var REMEMBER_KEY = 'px_remember_token';
  try {
    if (!sessionStorage.getItem('token') && window.PXAPI && typeof PXAPI.getToken === 'function') {
      var saved = localStorage.getItem(REMEMBER_KEY);
      if (saved) PXAPI.setToken(saved);
    }
  } catch (e) { /* storage unavailable: the tab-scoped session still works */ }

  /* ---------- session state ---------- */
  function setGuest() {
    document.body.classList.add('is-guest');
    document.body.classList.remove('is-auth');
  }
  function setAuth() {
    document.body.classList.remove('is-guest');
    document.body.classList.add('is-auth');
  }
  // The upstream returns success on /wps/member/info even for anonymous
  // visitors. Require a real member identity before showing the logged-in UI.
  function hasIdentity(d) {
    if (!d || typeof d !== 'object') return false;
    var probe = d.memberInfo || d.member || d.userInfo || d;
    if (!probe || typeof probe !== 'object') return false;
    var keys = ['memberId', 'customerId', 'userId', 'memberCode', 'memberNo',
      'customerNo', 'memberAccount', 'loginId', 'loginName', 'username',
      'nickname', 'account', 'mobile', 'mobileNum', 'phone', 'email'];
    for (var i = 0; i < keys.length; i++) {
      var v = probe[keys[i]];
      if (v != null && v !== '' && v !== 0 && v !== '0') return true;
    }
    return false;
  }

  /* ---------- social login (parity with the reference design) ----------
     The upstream has a working Facebook login only inside its native app
     (on the web its own button answers "coming soon") and no Google login at
     all, so these circles are shown for parity and say so instead of sitting
     there doing nothing. */
  $$('[data-social]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      toast((btn.getAttribute('data-social') || 'Social') + ' login is coming soon.');
    });
  });

  /* ---------- password show/hide ---------- */
  $$('[data-pw-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-pw-toggle'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });

  /* ---------- bottom nav active state ---------- */
  $$('.nav__item[data-nav]').forEach(function (item) {
    var here = location.pathname;
    var match = item.getAttribute('data-nav');
    if ((match === 'home' && (here === '/m/home' || here === '/m/index.html' || here === '/m/' || here === '/m')) ||
        (here.indexOf('/' + match) === 0)) {
      item.classList.add('is-active');
    }
  });

  /* ---------- helpers for locked actions ---------- */
  function requireLogin(kind) {
    toast('Please log in to continue.', kind || '');
    setTimeout(function () { location.href = '/m/login'; }, 700);
  }
  $$('[data-requires-auth]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (document.body.classList.contains('is-guest')) {
        e.preventDefault();
        requireLogin();
      }
    });
  });

  /* ======================================================================
     Login page
     ====================================================================== */
  /* ---------- captcha ----------
     Upstream answers /wps/session/login with "Please Enter The Correct Captcha
     Code" when its risk rules ask for one, and the code it wants is the text of
     the image from /wps/captcha, sent as the payload's `captcha` field (its own
     form calls the input `identifying`). The image is bound to the captchaId
     cookie that call sets, so it has to be fetched in the same browser session
     that logs in - and a cached image is a wrong image, hence the cache-buster
     in PXAPI.captchaImage() plus the proxy's no-store on this path. A code is
     single-use, so every failed attempt loads a fresh one. */
  // Shared by the login and register forms (both carry these hooks).
  var captchaImg   = $('[data-captcha-img]');
  var captchaInput = $('[data-captcha-input]');
  var captchaBtn   = $('[data-captcha-refresh]');

  // An <img> with no src still reports the page URL, so ask for the attribute.
  function hasCaptcha() {
    return !!(captchaImg && captchaImg.getAttribute('src'));
  }

  function loadCaptcha(clearField) {
    if (!captchaImg || !window.PXAPI || typeof PXAPI.captchaImage !== 'function') return;
    if (clearField && captchaInput) captchaInput.value = '';
    captchaImg.removeAttribute('src');
    if (captchaBtn) captchaBtn.classList.add('is-loading');
    PXAPI.captchaImage()
      .then(function (url) {
        if (url) captchaImg.setAttribute('src', url);
        else toast('Could not load the image code.', 'error');
      })
      .catch(function (err) { toast((err && err.message) || 'Could not load the image code.', 'error'); })
      .then(function () { if (captchaBtn) captchaBtn.classList.remove('is-loading'); });
  }

  if (captchaBtn) captchaBtn.addEventListener('click', function () { loadCaptcha(true); });
  if (captchaInput) {
    captchaInput.addEventListener('focus', function () { if (!hasCaptcha()) loadCaptcha(false); });
  }
  if (captchaImg) loadCaptcha(false);

  var loginForm = $('#login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('button[type="submit"]', loginForm);
      var mobile = digits($('#login-mobile', loginForm).value);
      var password = $('#login-password', loginForm).value;
      var captcha = (($('[data-captcha-input]', loginForm) || {}).value || '').trim();

      if (mobile.length < 8) { toast('Enter a valid mobile number.', 'error'); return; }
      if (password.length < 4) { toast('Enter your password.', 'error'); return; }
      // Only insist on a code when there is an image to read it from, so an
      // unavailable captcha endpoint cannot lock everyone out of logging in.
      if (hasCaptcha() && !captcha) { toast('Enter the captcha.', 'error'); return; }

      busy(btn, true);
      // Upstream expects `username` (not `mobile`) + type + loginDeviceId.
      // PXAPI normalises `mobile` -> `username` and injects loginDeviceId,
      // but send the canonical shape here so the intent is explicit.
      var payload = { username: mobile, mobile: mobile, password: password, type: 'username' };
      if (captcha) payload.captcha = captcha;
      try {
        if (window.PXAPI && typeof PXAPI.loginDeviceId === 'function') {
          payload.loginDeviceId = PXAPI.loginDeviceId();
        }
      } catch (x) {}
      PXAPI.login(payload)
        .then(function (res) {
          // PXAPI.login already persists value.token -> sessionStorage
          // (Authorization for memberInfo/balance). Fall back to an explicit
          // save here in case the shape varies by build.
          try {
            var v = (res && res.value) || {};
            if (v.token && window.PXAPI && typeof PXAPI.setToken === 'function') {
              PXAPI.setToken(v.token, v);
              // "Remember" outlives the tab: PXAPI only keeps the token in
              // sessionStorage, so without a localStorage copy every new tab
              // (and every visit to an upstream page, which reads that key)
              // would look logged out.
              var remember = $('#login-remember');
              if (remember && remember.checked) {
                localStorage.setItem(REMEMBER_KEY, v.token);
              } else {
                localStorage.removeItem(REMEMBER_KEY);
              }
            }
          } catch (x) {}
          toast('Logged in. Redirecting…', 'ok');
          location.href = '/m/home';
        })
        .catch(function (err) {
          toast(err.message || 'Login failed.', 'error');
          // A captcha code is consumed by the attempt, so the next one needs a
          // new image (upstream's own form clears `identifying` and reloads it).
          if (hasCaptcha()) loadCaptcha(true);
        })
        .then(function () { busy(btn, false); });
    });
  }

  /* ======================================================================
     Register page
     ====================================================================== */
  var registerForm = $('#register-form');
  if (registerForm) {
    // No OTP step: the upstream signup has none (and the backend's register
    // field list has no SMS/code entry either - see problem-to-fix.md #4). The
    // image captcha above is what it verifies instead.
    registerForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('button[type="submit"]', registerForm);
      var mobile = digits($('#reg-mobile', registerForm).value);
      var password = $('#reg-password', registerForm).value;
      var confirm = $('#reg-confirm', registerForm).value;
      var captcha = (($('[data-captcha-input]', registerForm) || {}).value || '').trim();
      var referral = ($('#reg-invite', registerForm).value || '').trim();
      var agreed = $('#reg-agree', registerForm).checked;

      if (mobile.length < 8) { toast('Enter a valid mobile number.', 'error'); return; }
      // The backend accepts 6-12 characters, letters and digits only
      // (/wps/system/setting/register -> password.patternId 4).
      if (password.length < 6) { toast('Password must be at least 6 characters.', 'error'); return; }
      if (password.length > 12) { toast('Password must be at most 12 characters.', 'error'); return; }
      if (!/^[a-zA-Z0-9]+$/.test(password)) { toast('Password can only contain letters and numbers.', 'error'); return; }
      if (password !== confirm) { toast('Passwords do not match.', 'error'); return; }
      if (hasCaptcha() && !captcha) { toast('Enter the captcha.', 'error'); return; }
      if (!agreed) { toast('Please accept the terms to continue.', 'error'); return; }

      // `referralCode` is the name the upstream registration flow reads (its own
      // app posts it from localStorage.reg_info); `inviteCode` was never a field.
      var payload = { mobile: mobile, password: password };
      if (captcha) payload.captcha = captcha;
      if (referral) payload.referralCode = referral;

      busy(btn, true);
      PXAPI.registerMobile(payload)
        .then(function () {
          toast('Account created. Please log in.', 'ok');
          setTimeout(function () { location.href = '/m/login'; }, 900);
        })
        .catch(function (err) {
          toast(err.message || 'Registration failed.', 'error');
          if (hasCaptcha()) loadCaptcha(true);
        })
        .then(function () { busy(btn, false); });
    });
  }

  /* ======================================================================
     Session state from the backend

     The custom home hydrates itself (mobile/assets/home.js); what is left here
     is any element that asks the backend who the visitor is. An empty or
     placeholder success must never flip a guest to logged in.
     ====================================================================== */
  var balanceEl = $('#balance-amount');
  if (window.PXAPI && balanceEl) {
    PXAPI.memberInfo()
      .then(function (res) {
        var d = (res && res.value) || res || {};
        if (!hasIdentity(d)) { setGuest(); return; }
        setAuth();

        var amount = d.balance != null ? d.balance
                   : (d.availableBalance != null ? d.availableBalance : d.walletBalance);
        if (amount != null) balanceEl.textContent = Number(amount).toLocaleString('en-US', { maximumFractionDigits: 2 });
      })
      .catch(setGuest);
  }

  /* ======================================================================
     Home — game categories + "all games" grid, loaded from the backend.

     Categories come from the game relay (GCSGAME_getVassGameType), which
     returns value.content = { RNG: { JL: [], PG: [] ... }, LIVE: {...} }.

     Games come from GCSGAME_gameList (a GET relay). Every game carries its
     own logo (defaultIcon / showIcon) and vendor, so the grid renders the
     real catalogue rather than the static placeholders.

     If the upstream is unreachable the static markup in index.html stays up
     and a toast explains the failure (see the domain-check hint below).
     ====================================================================== */
  var catsEl      = $('#game-cats');
  var listEl      = $('#game-list');
  var moreBtn     = $('#games-more');
  var moreWrap    = $('#games-more-wrap');
  var gamesTitle  = $('#games-title');
  var gamesCount  = $('#games-count');

  // code -> friendly label from config.js.
  var GROUP_NAME = {};
  (window.PX_GAME_GROUPS || []).forEach(function (g) { GROUP_NAME[g.code] = g.name; });

  // Preferred category order (matches the upstream home page).
  var GROUP_ORDER = ['RNG', 'FISH', 'PVP', 'LIVE', 'SPORTS', 'ELOTT', 'LOTT', 'ESPORTS', 'COCKFIGHT'];

  var META = {
    merchant:   window.PX_MERCHANT || '',
    platform:   'html5',
    clientType: 2,
    language:   'en'
  };
  var PAGE_SIZE = 24;

  var state = {
    type:    '',          // '' = All
    name:    'All Games',
    page:    1,
    pages:   1,
    total:   null,
    loading: false
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function val(item, names) {
    for (var i = 0; i < names.length; i++) {
      var v = item[names[i]];
      if (v != null && v !== '') return v;
    }
    return '';
  }

  function num(n) { return Number(n || 0).toLocaleString('en-US'); }

  // The upstream game relay intermittently answers with a maintenance/error
  // payload (function.not.available) instead of data; a couple of quick retries
  // ride that out so the menu does not fall back to placeholders.
  function withRetry(fn, attempts) {
    attempts = attempts || 4;
    return new Promise(function (resolve, reject) {
      (function attempt(n) {
        fn().then(resolve).catch(function (err) {
          if (n < attempts) setTimeout(function () { attempt(n + 1); }, 500 * n);
          else reject(err);
        });
      })(1);
    });
  }

  function gameLogo(g) {
    // defaultIcon is the English/localised CDN copy; showIcon is the fallback.
    return val(g, ['defaultIcon', 'showIcon', 'imgUrl', 'imageUrl', 'icon', 'cover', 'logo']);
  }
  function gameName(g) {
    return val(g, ['defaultLanguage', 'gameName', 'nodeName', 'name', 'title']);
  }
  function gameVendor(g) {
    return val(g, ['displayName', 'vendorName', 'vendor', 'vassalage']);
  }

  /* ---------- categories ---------- */
  function renderCats(cats) {
    if (!catsEl) return;

    // Fallback: the known groups from config.js when the relay gave nothing.
    if (!cats || !cats.length) {
      cats = (window.PX_GAME_GROUPS || []).map(function (g) {
        return { code: g.code, name: g.name, vendors: [] };
      });
    }
    if (!cats.length) return;

    var all = { code: '', name: 'All', vendors: [] };
    var items = [all].concat(cats);

    catsEl.innerHTML = items.map(function (c) {
      var active = (c.code === state.type) ? ' is-active' : '';
      var count = c.vendors && c.vendors.length ? (c.vendors.length + ' providers') : '';
      return '<button type="button" class="cat' + active + '" data-type="' + esc(c.code) + '" data-name="' + esc(c.name) + '">' +
        '<span class="cat__icon">&#127918;</span>' +
        '<span class="cat__name">' + esc(c.name) + (c.code === '' ? ' Games' : '') + '</span>' +
        (count ? '<span class="cat__count">' + esc(count) + '</span>' : '') +
        '</button>';
    }).join('');

    $$('.cat', catsEl).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var type = btn.getAttribute('data-type') || '';
        if (type === state.type) return;
        state.type = type;
        state.name = btn.getAttribute('data-name') || 'All Games';
        $$('.cat', catsEl).forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        if (gamesTitle) gamesTitle.textContent = state.name + ' Games';
        loadGames(true);
      });
    });
  }

  function buildCats(content) {
    if (!content || typeof content !== 'object') return null;
    var seen = {};
    var cats = [];
    GROUP_ORDER.forEach(function (code) {
      if (content[code]) {
        seen[code] = 1;
        cats.push({ code: code, name: GROUP_NAME[code] || code, vendors: Object.keys(content[code] || {}) });
      }
    });
    Object.keys(content).forEach(function (code) {
      if (seen[code]) return;
      cats.push({ code: code, name: GROUP_NAME[code] || code, vendors: Object.keys(content[code] || {}) });
    });
    return cats;
  }

  /* ---------- games ---------- */
  function renderGames(list, append) {
    if (!listEl) return;
    var html = list.map(function (g) {
      var logo = gameLogo(g);
      var art = logo
        ? '<div class="game__art"><img src="' + esc(logo) + '" alt="" loading="lazy" referrerpolicy="no-referrer"></div>'
        : '<div class="game__art">&#127918;</div>';
      return '<a class="game game--grid" href="#" ' +
        'data-node="' + esc(val(g, ['nodeId', 'gameId', 'id'])) + '" ' +
        'data-type="' + esc(val(g, ['gameType'])) + '" ' +
        'data-vendor="' + esc(val(g, ['vassalage', 'vendorCode'])) + '" ' +
        'data-code="' + esc(val(g, ['nodeTypeManageId', 'gameCode'])) + '">' +
        art +
        '<div class="game__body">' +
          '<div class="game__name">' + esc(gameName(g)) + '</div>' +
          '<div class="game__tag">' + esc(gameVendor(g)) + '</div>' +
        '</div></a>';
    }).join('');

    if (append) listEl.insertAdjacentHTML('beforeend', html);
    else listEl.innerHTML = html;

    if (!list.length && !append) {
      listEl.innerHTML = '<p class="games-empty">No games found in this category.</p>';
    }
  }

  function loadGames(reset) {
    if (!listEl || !window.PXAPI || state.loading) return;
    state.loading = true;

    if (reset) {
      state.page = 1;
      listEl.innerHTML = '<p class="games-empty">Loading games…</p>';
    }
    if (moreBtn) busy(moreBtn, true);

    withRetry(function () {
      return PXAPI.gameList({
        merchant:   META.merchant,
        platform:   META.platform,
        clientType: META.clientType,
        language:   META.language,
        pageNo:     state.page,
        pageSize:   PAGE_SIZE,
        gameType:   state.type || ''
      });
    }, 4).then(function (res) {
      var d = (res && res.value) || res || {};
      var games = Array.isArray(d.games) ? d.games : [];
      state.pages = Number(d.totalPages || state.page) || state.page;
      state.total = (d.totalCount != null) ? d.totalCount : state.total;

      if (reset) listEl.innerHTML = '';
      renderGames(games, !reset);

      if (gamesCount) gamesCount.textContent = num(state.total != null ? state.total : games.length) + ' games';

      var hasMore = state.page < state.pages;
      if (moreWrap) moreWrap.style.display = hasMore ? '' : 'none';
    }).catch(function (err) {
      if (reset) {
        listEl.innerHTML = '<p class="games-empty">Could not load games right now.</p>';
      }
      toast(err && err.message ? err.message : 'Could not load games.', 'error');
    }).then(function () {
      state.loading = false;
      if (moreBtn) busy(moreBtn, false);
    });
  }

  if (moreBtn) {
    moreBtn.addEventListener('click', function () {
      if (state.page < state.pages) {
        state.page += 1;
        loadGames(false);
      }
    });
  }

  // Admin panel settings: the game language and whether games open in the
  // in-app window (/m/game, the one with the Back button) both come from there.
  if (window.PXAPI && PXAPI.loadSettings) { PXAPI.loadSettings(); }

  // Open a game (launch relay returns the play URL).
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('[data-node]') : null;
    if (!a || !window.PXAPI) return;
    e.preventDefault();
    var nodeId = a.getAttribute('data-node');
    if (!nodeId) { toast('Game unavailable.', 'error'); return; }
    // The in-app window launches the game itself, so this route must not launch
    // it first: a second launch while the round is open is refused (#734).
    var gs = PXAPI.gameSettings || {};
    if (gs.in_app !== false) {
      var vendor = a.getAttribute('data-vendor') || '';
      var gtype  = a.getAttribute('data-type') || '';
      var qs = 'node=' + encodeURIComponent(nodeId);
      if (vendor) qs += '&vendor=' + encodeURIComponent(vendor);
      if (gtype)  qs += '&gtype=' + encodeURIComponent(gtype);
      location.href = '/m/game?' + qs;
      return;
    }
    PXAPI.launchGame({
      gameType: a.getAttribute('data-type') || '',
      vassalage: a.getAttribute('data-vendor') || '',
      gameCode: a.getAttribute('data-code') || '',
      gameId: nodeId,
      nodeId: nodeId,
      platform: META.platform
    })
      .then(function (res) {
        // The play URL is nested under value.content.game_url; PXAPI.gameUrl
        // reads every shape the launch relay uses.
        var url = (window.PXAPI && PXAPI.gameUrl) ? PXAPI.gameUrl(res) : '';
        if (url) location.href = url;
        else toast('Could not open game.', 'error');
      })
      .catch(function (err) { toast(err && err.message ? err.message : 'Could not open game.', 'error'); });
  });

  if (window.PXAPI && (listEl || catsEl)) {
    // Categories first (so the chips exist), then the games grid.
    withRetry(function () {
      return PXAPI.gameMenus({ typeId: '1', merchant_code: META.merchant });
    }, 4)
      .then(function (res) {
        var content = (res && res.value && res.value.content) || (res && res.content) || null;
        renderCats(buildCats(content));
      })
      .catch(function () { renderCats(null); });

    loadGames(true);
  }

  /* ======================================================================
     Session-aware routing

     Login and register are pointless with a session already, so they ask the
     backend who the visitor is instead of trusting a flag. /wps/member/info
     answers 401 for anonymous visitors, so a rejected call means "no session"
     just as much as an empty identity does.
     ====================================================================== */
  var pageId = (document.body.getAttribute('data-page') || '').toLowerCase();
  if (window.PXAPI && (pageId === 'login' || pageId === 'register')) {
    PXAPI.memberInfo().then(function (res) {
      var d = (res && res.value) || res || {};
      if (hasIdentity(d)) location.replace('/m/home');
    }).catch(function () {});
  }

  /* ---------- logout ----------
     Log out lives on the home menu and in the upstream member centre; nothing
     on this page needs it. */
})();
