/* Product API aliases must share state and send each analytics event only once. */
'use strict';

const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
let failed = 0;
function check(label, ok) {
  if (!ok) failed++;
  console.log((ok ? 'ok   ' : 'FAIL ') + label);
}
const source = (file) => fs.readFileSync(path.join(__dirname, '../../public/assets/js', file), 'utf8');

const analytics = new JSDOM('<script data-api="/_a/collect"></script>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://site.test/page' });
const sent = [];
Object.defineProperty(analytics.window.document, 'currentScript', { value: analytics.window.document.querySelector('script') });
analytics.window.navigator.sendBeacon = (url, body) => { sent.push({ url, data: JSON.parse(body) }); return true; };
analytics.window.eval(source('faros-analytics.js'));
check('loading the tracker sends one page view', sent.length === 1 && sent[0].data.p === '/page');
check('both analytics names expose the same tracker', analytics.window.gnozio === analytics.window.faros);
analytics.window.gnozio.event('new-name');
analytics.window.faros.event('old-name');
check('both APIs send exactly one event through the existing endpoint', sent.length === 3 && sent.every(item => item.url === '/_a/collect') && sent[1].data.e === 'custom' && sent[1].data.v === 'new-name' && sent[2].data.v === 'old-name');
analytics.window.close();

const picker = new JSDOM('<meta name="media-picker-url" content="/admin/media-picker"><body><input data-image-field></body>', { runScripts: 'outside-only', url: 'https://site.test/admin' });
picker.window.eval(source('admin-media-picker.js'));
check('both media picker names expose the same picker', picker.window.GnozioMediaPicker === picker.window.FarosMediaPicker);
const input = picker.window.document.querySelector('input');
const before = picker.window.document.body.innerHTML;
picker.window.GnozioMediaPicker.enhance(input);
picker.window.FarosMediaPicker.enhance(input);
check('using both picker names does not duplicate controls', picker.window.document.body.innerHTML === before && input.dataset.imageReady === '1');
picker.window.close();

const icons = new JSDOM('<script type="application/json" id="icon-library">{"star":"<svg></svg>"}</script><body><select data-icon-picker><option value="star">star</option></select></body>', { runScripts: 'outside-only', url: 'https://site.test/admin' });
icons.window.eval(source('admin-icons.js'));
check('both icon picker names expose the same picker', !!icons.window.GnozioIconPicker && icons.window.GnozioIconPicker === icons.window.FarosIconPicker);
icons.window.close();

console.log(failed ? `${failed} FAILED` : 'ALL PASSED');
process.exit(failed ? 1 : 0);
