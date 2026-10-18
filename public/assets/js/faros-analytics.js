/*
 * The analytics of GnozioCMS (Admin > Analytics): reports a page view, and the clicks on links that leave the site, downloads, email and
 * phone links. No cookie is set and nothing is kept in the browser. What is sent is the page, where the visitor came from, the width of
 * the window and the kind of click; the server keeps no address. Visitors who ask not to be tracked (Do Not Track, Global Privacy Control)
 * are not counted, and neither are robots that say what they are. `gnozio.event("name")` counts an event of the page's own.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  var api = script && script.getAttribute('data-api');
  if (!api) { return; }
  var nav = navigator, win = window, doc = document;
  if (script.getAttribute('data-dnt') !== '0' && (nav.doNotTrack === '1' || win.doNotTrack === '1' || nav.msDoNotTrack === '1' || nav.globalPrivacyControl === true)) { return; }
  if (nav.webdriver || win._phantom || win.callPhantom || win.__nightmare) { return; }

  function send(data) {
    var body = JSON.stringify(data);
    try { if (nav.sendBeacon && nav.sendBeacon(api, body)) { return; } } catch (e) { /* fall back to a request */ }
    try { fetch(api, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' } }); } catch (e) { /* nothing more to try */ }
  }
  function page() { return { p: location.pathname, q: location.search, w: win.innerWidth || (win.screen && win.screen.width) || 0 }; }
  function view() { var data = page(); data.r = doc.referrer; send(data); }
  function event(type, value) { var data = page(); data.e = type; data.v = value || ''; send(data); }

  if (doc.visibilityState === 'prerender') {
    doc.addEventListener('visibilitychange', function once() {
      if (doc.visibilityState === 'visible') { doc.removeEventListener('visibilitychange', once); view(); }
    });
  } else {
    view();
  }

  if (script.getAttribute('data-links') !== '0') {
    doc.addEventListener('click', function (e) {
      var link = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      var url;
      if (!link) { return; }
      try { url = new URL(link.href, location.href); } catch (err) { return; }
      if (url.protocol === 'mailto:') { event('mailto', url.pathname.split('@').pop().split('?')[0]); return; }
      if (url.protocol === 'tel:') { event('tel', ''); return; }
      if (url.protocol !== 'http:' && url.protocol !== 'https:') { return; }
      if (url.hostname !== location.hostname) { event('outbound', url.hostname); return; }
      if (/\.(pdf|docx?|xlsx?|pptx?|zip|rar|7z|csv|txt|mp3|mp4|epub)$/i.test(url.pathname)) {
        var name = url.pathname.split('/').pop();
        try { name = decodeURIComponent(name); } catch (err) { /* keep it as it is */ }
        event('download', name);
      }
    }, true);
  }

  // Preserve existing sites' custom event calls while exposing the new product name.
  win.gnozio = win.faros = { event: function (name) { event('custom', String(name || '').slice(0, 100)); } };
})();
