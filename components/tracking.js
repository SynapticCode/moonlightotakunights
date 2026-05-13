/* ============================================================
 * tracking.js — Moonlight Otaku Nights engagement tracker
 *
 * Captures lightweight engagement signals beyond standard pageviews:
 *   - Scroll depth (25/50/75/100% — once per pageview)
 *   - Time on page milestones (30s / 60s / 120s — once per pageview)
 *   - Outbound link clicks (Discord, Instagram, TikTok, Linktree, etc.)
 *   - Gallery / lightbox interactions
 *   - Form start (first focus on a tracked form)
 *
 * Each event is fired to:
 *   1. The GTM dataLayer (so GA4/Meta tags configured in GTM see it)
 *   2. Meta Pixel directly (so the browser pixel records it for retargeting)
 *   3. The /api/track-beacon.php server endpoint (so CAPI + GA4 MP get it
 *      even for users with adblockers or Safari ITP)
 *
 * A shared eventID is generated client-side per event so Meta dedupes the
 * browser pixel against the server CAPI hit.
 * ============================================================ */

(function () {
  'use strict';

  if (window.__moonlightTracking) return; // idempotent
  window.__moonlightTracking = true;

  // ---- Config ----
  var BEACON_URL = '/api/track-beacon.php';
  var SCROLL_MILESTONES = [25, 50, 75, 100];
  var TIME_MILESTONES_SEC = [30, 60, 120];

  // ---- Utilities ----
  function uuid() {
    if (crypto && crypto.randomUUID) {
      try { return crypto.randomUUID(); } catch (e) {}
    }
    return 'xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function getCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function getGaClientId() {
    var ga = getCookie('_ga');
    if (!ga) return null;
    var parts = ga.split('.');
    if (parts.length < 4) return null;
    return parts[2] + '.' + parts[3];
  }

  // ---- Unified emit ----
  // Sends one logical event to dataLayer (GTM), fbq (Meta pixel), and our
  // server beacon. The eventID is shared so Meta can dedupe pixel vs CAPI.
  function emit(eventName, label, extra) {
    var eventId = uuid();
    var url = window.location.href;
    var data = Object.assign({
      event_id: eventId,
      event_label: label || '',
      page_location: url,
      page_title: document.title
    }, extra || {});

    // 1. GTM dataLayer — drives any GA4/Meta tags configured in the container
    try {
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push(Object.assign({ event: eventName }, data));
    } catch (e) {}

    // 2. Direct Meta Pixel call — keeps browser-side retargeting accurate
    // We tag every engagement signal as ViewContent (low-intent) so it
    // doesn't pollute conversion optimization but still feeds audiences.
    try {
      if (typeof window.fbq === 'function') {
        window.fbq('trackCustom', eventName, {
          content_name: eventName + (label ? ':' + label : ''),
          content_category: 'engagement'
        }, { eventID: eventId });
      }
    } catch (e) {}

    // 3. Server beacon — survives adblockers + Safari ITP. sendBeacon is
    // preferred because it doesn't block unload; fall back to fetch.
    try {
      var beaconPayload = {
        event: eventName,
        event_id: eventId,
        url: url,
        label: label || '',
        value: typeof extra === 'object' && extra && typeof extra.value === 'number' ? extra.value : null,
        client_id: getGaClientId(),
        fbp: getCookie('_fbp'),
        fbc: getCookie('_fbc')
      };
      var body = JSON.stringify(beaconPayload);
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        navigator.sendBeacon(BEACON_URL, blob);
      } else {
        fetch(BEACON_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: body,
          keepalive: true
        }).catch(function () {});
      }
    } catch (e) {}
  }

  // ---- Scroll depth ----
  // Fires once per milestone per pageview. We re-check on scroll + resize
  // because layout reflow can change the page height after load.
  var firedScroll = {};
  function checkScroll() {
    var doc = document.documentElement;
    var scrollTop = window.pageYOffset || doc.scrollTop || 0;
    var viewport  = window.innerHeight || doc.clientHeight || 0;
    var fullHeight = Math.max(
      document.body.scrollHeight, doc.scrollHeight,
      document.body.offsetHeight, doc.offsetHeight,
      doc.clientHeight
    );
    var scrollable = fullHeight - viewport;
    if (scrollable <= 0) return;
    var pct = Math.min(100, Math.round(((scrollTop + viewport) / fullHeight) * 100));
    SCROLL_MILESTONES.forEach(function (m) {
      if (!firedScroll[m] && pct >= m) {
        firedScroll[m] = true;
        emit('scroll_depth', String(m), { percent: m });
      }
    });
  }

  // ---- Time on page ----
  // We use elapsed *visible* time so background tabs don't inflate.
  var visibleMs = 0;
  var lastTick = Date.now();
  var firedTime = {};
  function tickTime() {
    var now = Date.now();
    if (!document.hidden) visibleMs += (now - lastTick);
    lastTick = now;
    var sec = Math.floor(visibleMs / 1000);
    TIME_MILESTONES_SEC.forEach(function (m) {
      if (!firedTime[m] && sec >= m) {
        firedTime[m] = true;
        emit('time_on_page', String(m), { seconds: m });
      }
    });
  }

  // ---- Outbound clicks ----
  // Any anchor whose host differs from the current host. We also explicitly
  // catch our key social destinations so we can label them cleanly.
  var SOCIAL_HOSTS = {
    'discord.gg': 'discord',
    'discord.com': 'discord',
    'instagram.com': 'instagram',
    'www.instagram.com': 'instagram',
    'tiktok.com': 'tiktok',
    'www.tiktok.com': 'tiktok',
    'linktr.ee': 'linktree',
    'open.spotify.com': 'spotify',
    'youtube.com': 'youtube',
    'www.youtube.com': 'youtube',
    'youtu.be': 'youtube'
  };
  function onClick(e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
    var url;
    try { url = new URL(href, window.location.href); } catch (err) { return; }
    if (url.host === window.location.host) return;
    var label = SOCIAL_HOSTS[url.host] || url.host;
    emit('outbound_click', label, { destination: url.href });
  }

  // ---- Lightbox / gallery (Miku recap page) ----
  // Listens for clicks on elements marked as gallery items. The recap page
  // uses `.gallery-thumb` or `[data-lightbox]`; we accept either.
  function onGalleryClick(e) {
    var el = e.target.closest('[data-lightbox], .gallery-thumb, .gallery-item, .gallery-photo');
    if (!el) return;
    var idx = el.getAttribute('data-index') || el.dataset.index || '';
    var src = (el.querySelector('img') || {}).src || el.getAttribute('href') || '';
    emit('gallery_view', idx ? ('photo:' + idx) : 'photo', { src: src });
  }

  // ---- Form start ----
  // First time the user focuses any field of a tracked form. We use
  // [data-track-form] as the opt-in marker so we don't fire on search bars,
  // honeypots, etc.
  var formStartFired = {};
  function onFormFocus(e) {
    var form = e.target && e.target.closest ? e.target.closest('form[data-track-form]') : null;
    if (!form) return;
    var name = form.getAttribute('data-track-form') || form.id || 'unknown';
    if (formStartFired[name]) return;
    formStartFired[name] = true;
    emit('form_start', name);
  }

  // ---- CTA clicks ----
  // Buttons / links marked with [data-track-cta] are treated as high-intent
  // signals separate from generic outbound clicks.
  function onCtaClick(e) {
    var el = e.target && e.target.closest ? e.target.closest('[data-track-cta]') : null;
    if (!el) return;
    var label = el.getAttribute('data-track-cta') || el.textContent.trim().slice(0, 64);
    emit('cta_click', label);
  }

  // ---- Init ----
  function init() {
    // Scroll
    var scrollTimer = null;
    window.addEventListener('scroll', function () {
      if (scrollTimer) return;
      scrollTimer = setTimeout(function () { scrollTimer = null; checkScroll(); }, 250);
    }, { passive: true });
    window.addEventListener('resize', checkScroll, { passive: true });
    checkScroll();

    // Time
    setInterval(tickTime, 5000);
    document.addEventListener('visibilitychange', function () {
      // Reset lastTick when becoming visible so the hidden window isn't counted.
      lastTick = Date.now();
    });

    // Clicks
    document.addEventListener('click', function (e) {
      onClick(e);
      onGalleryClick(e);
      onCtaClick(e);
    }, true);

    // Form focus (focusin bubbles, focus does not)
    document.addEventListener('focusin', onFormFocus, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
