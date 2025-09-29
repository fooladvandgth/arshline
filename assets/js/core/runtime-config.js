/*
  ARSHLINE Runtime Config Initializer
  - Reads JSON from <script id="arshline-config" type="application/json">...
  - Exposes legacy globals for backward compatibility:
      ARSHLINE_REST, ARSHLINE_NONCE, ARSHLINE_SUB_VIEW_BASE,
      ARSHLINE_CAN_MANAGE, ARSHLINE_LOGIN_URL
  - Also exposes a frozen namespace: window.ARSHLINE_CFG
*/
(function RuntimeConfig(){
  try {
    var el = document.getElementById('arshline-config');
    if (!el) { console.error('ARSHLINE: config element not found'); return; }
    var raw = el.textContent || el.innerText || '';
    var cfg = {};
    try { cfg = JSON.parse(raw || '{}'); } catch (e) {
      console.error('ARSHLINE: invalid config JSON', e);
      cfg = {};
    }
    // Normalize and expose globals (preserve existing public API names)
    window.ARSHLINE_REST = String(cfg.rest || '');
    window.ARSHLINE_NONCE = String(cfg.nonce || '');
    window.ARSHLINE_SUB_VIEW_BASE = String(cfg.sub_view_base || '');
    window.ARSHLINE_CAN_MANAGE = !!cfg.can_manage;
    window.ARSHLINE_LOGIN_URL = String(cfg.login_url || '');
    // Expose full config for module consumers
    try { Object.freeze && (cfg = Object.freeze(cfg)); } catch(_){}
    window.ARSHLINE_CFG = cfg;
  } catch (e) {
    console.error('ARSHLINE: failed to initialize config', e);
  }
})();
