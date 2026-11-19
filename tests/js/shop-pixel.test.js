/*
 * The Meta Pixel never sees a key or a token (themes/default/assets/js/shop-pixel.js, run as shipped in jsdom; audit S2).
 *
 *   1. the order's page     ?key=… is cut from the address before Meta's script is added; the Purchase is still queued, once
 *   2. a token              an address with ?token=… loads nothing of Meta's
 *   3. other pages          an ordinary query stays, and the Pixel loads
 *   4. no consent yet       with the banner and no answer, the key stays in the address and nothing loads
 *
 *   node tests/js/shop-pixel.test.js        (after: npm ci --prefix tests/js)
 */
'use strict';

const fs = require('fs');
const path = require('path');

let JSDOM;
try {
  ({ JSDOM } = require('jsdom'));
} catch (error) {
  console.log('FAIL jsdom is not installed: run  npm ci --prefix tests/js');
  process.exit(1);
}

let failed = 0;
function check(label, ok, detail) {
  if (!ok) { failed++; }
  console.log((ok ? 'ok   ' : 'FAIL ') + label + (ok ? '' : '  ' + String(detail === undefined ? '' : detail)));
}
const source = fs.readFileSync(path.join(__dirname, '..', '..', 'themes', 'default', 'assets', 'js', 'shop-pixel.js'), 'utf8');

function page(url, consent, track) {
  const data = track ? `<script type="application/json" data-shop-track>${JSON.stringify(track)}</script>` : '';
  const dom = new JSDOM(`<!doctype html><head><script></script></head><body>${data}</body>`, { url, runScripts: 'outside-only' });
  const { window } = dom;
  // What the address was when Meta's loader added its script.
  const seen = [];
  const insert = window.Node.prototype.insertBefore;
  window.Node.prototype.insertBefore = function (node, ref) {
    if (node && node.src && node.src.includes('connect.facebook.net')) seen.push(window.location.href);
    return insert.call(this, node, ref);
  };
  const tag = window.document.createElement('script');
  tag.setAttribute('data-pixel', '1234567890123');
  tag.setAttribute('data-consent', consent);
  window.document.body.appendChild(tag);
  Object.defineProperty(window.document, 'currentScript', { value: tag, configurable: true });
  window.eval(source);
  return { window, seen };
}

const purchase = { event: 'Purchase', id: 'order-AL-1', once: true, data: { value: 124, currency: 'EUR' } };

// ---- 1. the order's page
let p = page('https://shop.example/checkout/order/AL-2026-00042?key=SECRETKEY123#top', 'none', purchase);
check('the key is cut from the address before Meta\'s script is added', p.seen.length === 1 && !p.seen[0].includes('SECRETKEY123') && p.seen[0] === 'https://shop.example/checkout/order/AL-2026-00042#top', p.seen);
check('and stays cut', !p.window.location.href.includes('key='), p.window.location.href);
const queued = (p.window.fbq && p.window.fbq.queue || []).map((args) => Array.from(args));
check('the Purchase is still sent, with its event id', queued.some((a) => a[0] === 'track' && a[1] === 'Purchase' && a[3] && a[3].eventID === 'order-AL-1'), JSON.stringify(queued));
check('and nothing queued names the key', !JSON.stringify(queued).includes('SECRETKEY123'));

// ---- 2. a token
p = page('https://shop.example/account/reset?token=RESETTOKEN', 'none');
check('an address with a token loads nothing of Meta\'s', p.seen.length === 0 && !p.window.fbq, p.seen);
p = page('https://shop.example/en/somewhere?a=1&token=X', 'none');
check('wherever the token is in the query', p.seen.length === 0, p.seen);

// ---- 3. other pages
p = page('https://shop.example/products/ring?colour=gold', 'none');
check('an ordinary page loads the Pixel and keeps its query', p.seen.length === 1 && p.window.location.search === '?colour=gold', p.seen);
check('PageView is sent', (p.window.fbq.queue || []).some((a) => a[0] === 'track' && a[1] === 'PageView'));

// ---- 4. no consent yet
p = page('https://shop.example/checkout/order/AL-2026-00042?key=SECRETKEY123', 'banner', purchase);
check('without an answer to the banner, nothing loads and the key stays', p.seen.length === 0 && p.window.location.search === '?key=SECRETKEY123', p.window.location.href);
p.window.gnozioConsent.grant();
check('agreeing cuts it, then loads', p.seen.length === 1 && !p.seen[0].includes('SECRETKEY123'), p.seen);

console.log(failed ? `\n${failed} FAILED` : '\nALL PASSED');
process.exit(failed ? 1 : 0);
