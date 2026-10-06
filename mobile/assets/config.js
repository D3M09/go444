/* ==========================================================================
   Site config for the custom mobile frontend.
   Loaded before api.js.
   ========================================================================== */
(function (global) {
  // Merchant / whitelabel code sent as the "Merchant" header on backend calls.
  // This is the go444 code from the upstream brand config
  // (brand:{platform:"WEB",merchant:"go44bdtf5"}). The backend resolves the
  // merchant from this header, so it must always match the active upstream.
  global.PX_MERCHANT = 'go44bdtf5';

  // Game category groups used by the upstream home page.
  global.PX_GAME_GROUPS = [
    { code: 'RNG',       name: 'Slots' },
    { code: 'FISH',      name: 'Fishing' },
    { code: 'PVP',       name: 'PVP' },
    { code: 'LIVE',      name: 'Live Casino' },
    { code: 'SPORTS',    name: 'Sports' },
    { code: 'ELOTT',     name: 'E-Lottery' },
    { code: 'LOTT',      name: 'Lottery' },
    { code: 'ESPORTS',   name: 'E-Sports' },
    { code: 'COCKFIGHT', name: 'Cockfight' }
  ];
})(window);
