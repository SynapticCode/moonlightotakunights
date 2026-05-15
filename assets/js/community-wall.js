/*!
 * community-wall.js — Render approved UGC photos into <article id="community-wall">.
 *
 * Pulls /api/ugc/list.php?event=<slug> from the dashboard subdomain and
 * fills #community-wall-grid. Falls back silently to the empty-state
 * placeholder if the endpoint is unreachable or returns zero items.
 */
(function () {
  'use strict';

  var API_BASE = 'https://dashboard.moonlightotakunights.com/api/ugc/list.php';

  function render(items) {
    var grid  = document.getElementById('community-wall-grid');
    var empty = document.getElementById('community-wall-empty');
    if (!grid) return;

    if (!items || !items.length) {
      // Leave empty-state in place.
      return;
    }
    if (empty) empty.remove();

    var frag = document.createDocumentFragment();
    items.forEach(function (it) {
      var tile = document.createElement('figure');
      tile.className = 'community-wall-tile';

      var img = document.createElement('img');
      img.src = it.url;
      img.loading = 'lazy';
      img.alt = it.caption || (it.handle ? '@' + it.handle : 'Community photo');
      tile.appendChild(img);

      if (it.handle) {
        var cap = document.createElement('figcaption');
        cap.className = 'community-wall-tile-caption';
        var a = document.createElement('a');
        a.href = 'https://instagram.com/' + it.handle;
        a.setAttribute('data-ig', it.handle);
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = '@' + it.handle;
        cap.appendChild(a);
        tile.appendChild(cap);
      }

      frag.appendChild(tile);
    });
    grid.appendChild(frag);

    // Re-wire IG links so the new anchors get smart-tab behavior.
    if (window.MoonlightIG && typeof window.MoonlightIG.wire === 'function') {
      window.MoonlightIG.wire();
    }
  }

  function load() {
    var host = document.getElementById('community-wall');
    if (!host) return;
    var slug = host.getAttribute('data-event') || '';
    if (!slug) return;

    fetch(API_BASE + '?event=' + encodeURIComponent(slug), {
      method: 'GET',
      credentials: 'omit',
      mode: 'cors'
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.ok) render(j.items); })
      .catch(function () { /* swallow — empty state stays */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
  }
})();
