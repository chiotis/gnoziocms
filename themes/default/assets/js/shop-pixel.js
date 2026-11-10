/*
 * The Meta Pixel of Shop settings › Marketing (src/Shop/ShopMarketing.php). It runs only after the visitor agrees: the site's banner
 * asks (data-consent="banner"), or the site's own consent tool calls gnozioConsent.grant() ("external"); "none" runs it at once.
 * The answer is the cookie gz_consent, which the server also reads before it sends a purchase to Meta. The footer's Cookie settings
 * (or any link to #cookie-settings) opens the banner again, so the answer can be changed at any time.
 *
 * The events: PageView on every page, and those the page gives in <script type="application/json" data-shop-track> (ViewContent,
 * InitiateCheckout, Purchase with its event id, sent once per browser), and AddToCart from shop.js (the event gz:shop-track).
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) return;
  var pixel = script.getAttribute('data-pixel');
  var mode = script.getAttribute('data-consent') || 'banner';
  var started = false;
  var NAME = 'gz_consent';

  function answer() {
    var found = document.cookie.match(/(?:^|;\s*)gz_consent=(granted|denied)/);
    return found ? found[1] : '';
  }

  function remember(value) {
    document.cookie = NAME + '=' + value + '; path=/; max-age=15552000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }

  function track(item) {
    if (!item || !item.event || !window.fbq) return;
    if (item.once && item.id) {
      try {
        if (localStorage.getItem('gz_sent_' + item.id)) return;
        localStorage.setItem('gz_sent_' + item.id, '1');
      } catch (e) { /* no storage: send it */ }
    }
    window.fbq('track', item.event, item.data || {}, item.id ? { eventID: item.id } : undefined);
  }

  function start() {
    if (started || !pixel) return;
    started = true;
    /* Meta's own loader, as Events Manager gives it. */
    !function (f, b, e, v, n, t, s) {
      if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
      if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = []; t = b.createElement(e); t.async = !0;
      t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
    }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
    window.fbq('init', pixel);
    window.fbq('track', 'PageView');
    document.querySelectorAll('script[data-shop-track]').forEach(function (node) {
      try { track(JSON.parse(node.textContent)); } catch (e) { /* a broken one is skipped */ }
    });
  }

  document.addEventListener('gz:shop-track', function (event) { if (started) track(event.detail); });

  // Changing one's mind as easily as agreeing: the footer's Cookie settings, or any link to #cookie-settings (a menu item).
  document.addEventListener('click', function (event) {
    var link = event.target instanceof Element ? event.target.closest('[data-consent-open], a[href$="#cookie-settings"]') : null;
    if (!link) return;
    event.preventDefault();
    showBanner();
  });

  var banner = null;
  function closeBanner() { if (banner) { banner.remove(); banner = null; } }

  function showBanner() {
    if (banner && banner.isConnected) return;
    banner = document.createElement('div');
    banner.className = 'gz-consent';
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-live', 'polite');
    banner.setAttribute('aria-label', script.getAttribute('data-more') || 'Privacy');
    var text = document.createElement('p');
    text.className = 'gz-consent-text';
    text.textContent = script.getAttribute('data-text') + ' ';
    var url = script.getAttribute('data-more-url');
    if (url) {
      var more = document.createElement('a');
      more.href = url;
      more.textContent = script.getAttribute('data-more');
      text.appendChild(more);
    }
    var actions = document.createElement('div');
    actions.className = 'gz-consent-actions';
    var no = document.createElement('button');
    no.type = 'button';
    no.className = 'btn btn-secondary';
    no.textContent = script.getAttribute('data-decline');
    no.addEventListener('click', function () { window.gnozioConsent.deny(); });
    var yes = document.createElement('button');
    yes.type = 'button';
    yes.className = 'btn btn-primary';
    yes.textContent = script.getAttribute('data-accept');
    yes.addEventListener('click', function () { window.gnozioConsent.grant(); });
    actions.appendChild(no);
    actions.appendChild(yes);
    banner.appendChild(text);
    banner.appendChild(actions);
    document.body.appendChild(banner);
  }

  /* For the site's own consent tool, and for a "cookie settings" link (gnozioConsent.open()). Saying no after yes takes effect on
     the next page: the Pixel already loaded on this one is not loaded again. */
  window.gnozioConsent = {
    grant: function () { remember('granted'); closeBanner(); start(); },
    deny: function () {
      var was = answer() === 'granted';
      remember('denied');
      closeBanner();
      if (was) location.reload();
    },
    open: showBanner,
    state: answer
  };

  if (mode === 'none' || answer() === 'granted') start();
  else if (mode === 'banner' && answer() === '') showBanner();
})();
