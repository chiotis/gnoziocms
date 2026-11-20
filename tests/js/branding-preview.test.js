/* The shared preview script with real Branding-shaped responses (CSS, not HTML). */
'use strict';
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/admin-preview.js'), 'utf8');
let failed = 0;
function check(label, value) {
  console.log((value ? 'ok   ' : 'FAIL ') + label);
  if (!value) { failed++; }
}
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
const markup = mode => `<form><input name="_csrf" value="token">
  <div data-preview data-mode="${mode}" data-endpoint="/admin/theme?preview=${mode === 'css' ? 'branding' : 'page'}">
    <input name="theme_settings[appearance][palette]" value="slate">
    <input name="bp_size_branding" type="radio" value="1280" data-bp-size checked>
    <input name="bp_mode_branding" type="radio" value="" data-bp-mode checked>
    <input name="bp_mode_branding" type="radio" value="dark" data-bp-mode>
    <input name="bp_view_branding" type="radio" value="site" data-bp-view checked>
    <input name="bp_view_branding" type="radio" value="styles" data-bp-view data-url="/admin/theme?preview=styles">
    <button type="button" data-bp-reload>Reload</button><div data-bp-stage><iframe data-bp-frame></iframe></div>
  </div></form>`;
function boot(mode = 'css') {
  const dom = new JSDOM(markup(mode), { url: 'http://localhost/admin/theme', runScripts: 'outside-only' });
  const w = dom.window, d = w.document, root = d.querySelector('[data-preview]'), frame = d.querySelector('iframe');
  const calls = [];
  let answer = { css: ':root{--accent-l:#ff0000}', attributes: { 'data-theme': 'rose', 'data-mode': 'light' }, logo: '', logo_dark: '', show_name: false, name: 'Sample site' };
  let preview;
  function fresh() {
    preview = d.implementation.createHTMLDocument('Site');
    preview.documentElement.setAttribute('data-mode', 'light');
    preview.head.innerHTML = '<link rel="stylesheet" href="/_theme/default/css/site.css"><link rel="stylesheet" href="/_custom/css/custom.css?v=1">';
    preview.body.innerHTML = '<a class="site-brand" aria-label="Old logo"><img src="old.svg"></a>';
    return preview;
  }
  fresh();
  Object.defineProperty(frame, 'contentDocument', { get: () => preview });
  w.matchMedia = () => ({ matches: false });
  w.fetch = (url, options) => {
    calls.push({ url, options });
    if (url === '/admin/theme?preview=styles') {
      return Promise.resolve({ ok: true, redirected: false, text: () => Promise.resolve('<!doctype html><title>Style samples</title><h1>Heading one</h1>') });
    }
    return Promise.resolve({ ok: true, json: () => Promise.resolve(answer) });
  };
  w.eval(source);
  return { w, d, root, frame, calls, fresh, doc: () => preview, answer: value => { answer = value; }, refresh: async () => { root.dispatchEvent(new w.Event('preview:refresh')); await wait(20); } };
}
(async () => {
  let t = boot();
  let drawn = 0;
  t.root.addEventListener('preview:drawn', () => { drawn++; });
  const field = t.d.querySelector('[name^="theme_settings"]');
  field.value = 'rose';
  field.dispatchEvent(new t.w.Event('input', { bubbles: true }));
  await wait(300);
  check('a normal field change requests the CSS preview', t.calls.length === 1 && t.calls[0].options.body.get(field.name) === 'rose');
  check('Branding JSON without html applies the CSS and attributes', t.doc().getElementById('gnozio-branding')?.textContent === ':root{--accent-l:#ff0000}' && t.doc().documentElement.getAttribute('data-theme') === 'rose');
  check('the preview preserves the custom stylesheet priority', t.doc().getElementById('gnozio-branding').nextElementSibling?.getAttribute('href') === '/_custom/css/custom.css?v=1');
  check('each CSS draw is announced for inherited-value indicators', drawn === 1);
  check('removing an existing logo previews the name, with no stale image or label', !t.doc().querySelector('.site-brand img') && t.doc().querySelector('.site-brand-title').textContent === 'Sample site' && !t.doc().querySelector('.site-brand').hasAttribute('aria-label'));
  t.answer({ error: 'Invalid', css: '', attributes: {} });
  await t.refresh();
  check('an invalid response keeps the previous preview', t.doc().getElementById('gnozio-branding').textContent.includes('#ff0000'));
  t.answer({ html: '<p>Wrong response</p>' });
  await t.refresh();
  check('an HTML response cannot replace a CSS preview', t.doc().getElementById('gnozio-branding').textContent.includes('#ff0000'));
  t.answer({ css: '', attributes: { 'data-theme': 'slate', 'data-mode': 'light' }, name: 'Sample site', logo: '' });
  await t.refresh();
  check('a reset refresh clears old CSS and restores the palette', t.doc().getElementById('gnozio-branding').textContent === '' && t.doc().documentElement.getAttribute('data-theme') === 'slate');
  t.w.close();

  t = boot();
  const dark = t.d.querySelector('[data-bp-mode][value="dark"]');
  dark.checked = true; dark.dispatchEvent(new t.w.Event('change'));
  check('dark preview can be forced before changing any setting', t.doc().documentElement.classList.contains('dark'));
  const auto = t.d.querySelector('[data-bp-mode][value=""]');
  auto.checked = true; auto.dispatchEvent(new t.w.Event('change'));
  check('Auto restores the original mode instead of retaining forced dark', t.doc().documentElement.getAttribute('data-mode') === 'light' && !t.doc().documentElement.classList.contains('dark'));
  await t.refresh();
  const samples = t.d.querySelector('[data-bp-view][value="styles"]');
  samples.checked = true; samples.dispatchEvent(new t.w.Event('change'));
  await wait(20);
  check('Style samples draws fetched HTML without framing an admin page', t.frame.srcdoc.includes('<h1>Heading one</h1>') && t.frame.title === 'Style samples');
  t.fresh(); t.frame.dispatchEvent(new t.w.Event('load'));
  check('a newly loaded specimen receives the unsaved palette and CSS', t.doc().documentElement.getAttribute('data-theme') === 'rose' && t.doc().getElementById('gnozio-branding').textContent.includes('#ff0000'));
  const site = t.d.querySelector('[data-bp-view][value="site"]');
  site.checked = true; site.dispatchEvent(new t.w.Event('change'));
  check('returning to Site clears the sample document', !t.frame.hasAttribute('srcdoc') && t.frame.title === 'Preview of the site');
  t.w.close();

  t = boot();
  t.doc().head.insertAdjacentHTML('afterbegin', '<style id="faros-branding">old</style>');
  await t.refresh();
  check('a theme copied before the rename keeps its old style element, which the preview fills', t.doc().getElementById('faros-branding')?.textContent.includes('#ff0000') && !t.doc().getElementById('gnozio-branding'));
  t.w.close();

  t = boot('page');
  t.answer({ html: '<p>Header page</p>', path: '/about' });
  await t.refresh();
  check('the Header/Footer page preview still accepts HTML', t.frame.srcdoc === '<p>Header page</p>');
  t.answer({ css: ':root{}', attributes: {} });
  await t.refresh();
  check('a malformed page response retains the last HTML', t.frame.srcdoc === '<p>Header page</p>');
  t.w.close();
  console.log(failed ? 'FAILED: ' + failed : 'ALL PASSED');
  process.exitCode = failed ? 1 : 0;
})();
