/**
 * tracking-stape.js
 *
 * If a Stape sGTM endpoint is configured on the page via
 *   <meta name="moonlight:sgtm-url" content="https://sgtm.example.com">
 * (or window.MOONLIGHT_SGTM_URL), this script reconfigures GA4 to
 * route hits through that endpoint via gtag's `transport_url` and
 * `first_party_collection`.
 *
 * If no endpoint is set, this is a no-op — GA4 stays on google-analytics.com.
 *
 * Must load AFTER the existing GA4 gtag('config', ...) call so the second
 * config call overrides the transport.
 */
(function () {
  function readEndpoint() {
    if (typeof window.MOONLIGHT_SGTM_URL === 'string' && window.MOONLIGHT_SGTM_URL) {
      return window.MOONLIGHT_SGTM_URL;
    }
    var m = document.querySelector('meta[name="moonlight:sgtm-url"]');
    return m ? (m.getAttribute('content') || '').trim() : '';
  }

  function readMeasurementId() {
    var m = document.querySelector('meta[name="moonlight:ga4-id"]');
    if (m && m.getAttribute('content')) return m.getAttribute('content').trim();
    return 'G-8W7W5FKYV9';
  }

  var endpoint = readEndpoint();
  if (!endpoint) return;
  if (typeof window.gtag !== 'function') return;

  // Re-configure GA4 to transport via sGTM.
  // - transport_url: send hits to your Stape endpoint
  // - first_party_collection: required so cookies are set on your domain
  window.gtag('config', readMeasurementId(), {
    transport_url: endpoint,
    first_party_collection: true,
    send_page_view: false  // page_view was already sent by the first config call
  });
})();

/**
 * UTM first-touch persistence & form forwarding.
 *
 * On every page load, capture any utm_* params from window.location.search
 * and remember them in localStorage under "mln_first_utm" (only the FIRST
 * time we see them — we never overwrite). Then, on every form submit and
 * fetch() POST to /api/*, append those params so the server can persist
 * them on the contact row (see contacts_capture_utm in bootstrap.php).
 *
 * Powers the analytics page's "Email → Signup" panel: when SES emails tag
 * outbound links with utm_source=ses + utm_campaign=broadcast_<id>, a
 * recipient who clicks through and then signs up gets attribution.
 */
(function () {
  var KEY = 'mln_first_utm';
  var FIELDS = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'];

  function fromQuery() {
    var out = {};
    try {
      var p = new URLSearchParams(window.location.search);
      FIELDS.forEach(function (f) {
        var v = p.get(f);
        if (v) out[f] = v.substring(0, 120);
      });
    } catch (_) {}
    return out;
  }

  function load() {
    try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; }
    catch (_) { return {}; }
  }

  function save(obj) {
    try { localStorage.setItem(KEY, JSON.stringify(obj)); } catch (_) {}
  }

  // 1. Persist first-touch. Only writes a field if it isn't already set.
  var current = load();
  var seen = fromQuery();
  var changed = false;
  FIELDS.forEach(function (f) {
    if (!current[f] && seen[f]) { current[f] = seen[f]; changed = true; }
  });
  if (changed) save(current);

  // 2. On every HTML form submit, inject hidden utm_* fields.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || form.tagName !== 'FORM') return;
    var utm = load();
    FIELDS.forEach(function (f) {
      if (!utm[f]) return;
      if (form.querySelector('input[name="' + f + '"]')) return;
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = f;
      input.value = utm[f];
      form.appendChild(input);
    });
  }, true);

  // 3. For fetch() POSTs to /api/* (JSON bodies), merge utm into the payload.
  var _fetch = window.fetch;
  if (typeof _fetch === 'function') {
    window.fetch = function (input, init) {
      try {
        var url = (typeof input === 'string') ? input : (input && input.url) || '';
        var method = (init && init.method) || (input && input.method) || 'GET';
        if (url.indexOf('/api/') !== -1 && /POST|PUT|PATCH/i.test(method)) {
          var utm = load();
          if (Object.keys(utm).length && init && typeof init.body === 'string') {
            try {
              var json = JSON.parse(init.body);
              if (json && typeof json === 'object' && !Array.isArray(json)) {
                FIELDS.forEach(function (f) {
                  if (utm[f] && json[f] == null) json[f] = utm[f];
                });
                init = Object.assign({}, init, { body: JSON.stringify(json) });
              }
            } catch (_) {}
          }
        }
      } catch (_) {}
      return _fetch.call(this, input, init);
    };
  }
})();
