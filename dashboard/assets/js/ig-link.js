/*!
 * ig-link.js — Smart Instagram link behavior.
 *
 * Auto-wires any <a data-ig="handle"> element:
 *
 *  Phone (touch device): tap opens the Instagram app via
 *    instagram://user?username=<handle>. If the app isn't installed,
 *    the OS falls through to the anchor's href, which lands on
 *    instagram.com in the mobile browser.
 *
 *  Laptop / desktop: click opens https://instagram.com/<handle> in
 *    a new tab BEHIND the current page so the user keeps scrolling
 *    our content. Safari may bring the new tab forward anyway —
 *    documented browser limitation, no workaround.
 *
 * Without JS the link still works as a plain target="_blank" anchor.
 */
(function () {
  'use strict';

  function isMobile() {
    // Coarse pointer (touch primary) + UA fallback. Cheap and good enough.
    if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) return true;
    return /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
  }

  function handleClick(e) {
    var a = e.currentTarget;
    var handle = (a.getAttribute('data-ig') || '').replace(/^@/, '').trim();
    if (!handle) return; // let default happen

    if (isMobile()) {
      // Try the app deep link. If the app isn't installed, the page
      // stays put and the anchor's normal href click finishes the nav.
      // We intentionally do NOT preventDefault — we want the https
      // fallback to fire naturally.
      var appUrl = 'instagram://user?username=' + encodeURIComponent(handle);
      // Use location for the deep-link attempt; the OS will swallow it
      // if the app exists, otherwise the existing http nav continues.
      // Hidden iframe technique avoids breaking the anchor fallback.
      try {
        var f = document.createElement('iframe');
        f.style.display = 'none';
        f.src = appUrl;
        document.body.appendChild(f);
        setTimeout(function () { try { f.remove(); } catch (_) {} }, 800);
      } catch (_) {}
      return; // let the anchor's normal navigation happen
    }

    // Desktop: open in background tab.
    e.preventDefault();
    var url = a.href || ('https://instagram.com/' + handle);
    var newTab = window.open(url, '_blank');
    if (newTab) {
      newTab.blur();
      window.focus();
    }
  }

  function wire() {
    var anchors = document.querySelectorAll('a[data-ig]');
    for (var i = 0; i < anchors.length; i++) {
      var a = anchors[i];
      if (a.__igWired) continue;
      a.__igWired = true;
      // Ensure the visible href is the web fallback.
      if (!a.getAttribute('href')) {
        var h = (a.getAttribute('data-ig') || '').replace(/^@/, '');
        a.setAttribute('href', 'https://instagram.com/' + h);
      }
      if (!a.getAttribute('target')) a.setAttribute('target', '_blank');
      if (!a.getAttribute('rel'))    a.setAttribute('rel', 'noopener');
      a.addEventListener('click', handleClick, false);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }

  window.MoonlightIG = { wire: wire };
})();
