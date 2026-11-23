/*
 * The section list of Settings and Shop settings (public/assets/js/admin.js, run as shipped in jsdom;
 * the markup is what admin/templates/partials/section-nav.twig writes).
 *
 *   1. a click switches     the panel shows, the others hide, the link is current, the select and active_tab follow
 *   2. the address follows  ?tab= is the chosen section, so a reload or a shared link opens it; Discard points there too
 *   3. the select switches  choosing in the select (narrow screens) does the same
 *   4. a new tab is a link  a ctrl/cmd click is left to the browser
 *   5. #key opens it        /admin/settings#backup opens Backups
 *   6. unknown keys         an unknown #key changes nothing
 *   7. links mode           in Shop settings a click is a plain link (not stopped)
 *
 *   node tests/js/section-nav.test.js        (after: npm ci --prefix tests/js)
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
const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'admin.js'), 'utf8');

const sections = [['basics', 'General'], ['smtp', 'Email / SMTP'], ['backup', 'Backups']];
function page(mode, url, current) {
  const base = mode === 'switch' ? '/admin/settings' : '/admin/shop-settings';
  const link = ([key, label]) => `<li><a href="${base}?tab=${key}" class="section-nav-link" data-section="${key}"${key === current ? ' aria-current="page"' : ''}><svg></svg><span>${label}</span></a></li>`;
  const option = ([key, label]) => `<option value="${key}" data-href="${base}?tab=${key}"${key === current ? ' selected' : ''}>${label}</option>`;
  const panel = ([key]) => `<div class="section-panel${key === current ? ' active' : ' hidden'}" data-panel="${key}">${key}</div>`;
  const html = `<!doctype html><body><form method="post">
    <input type="hidden" name="active_tab" value="${current}" data-active-tab-input>
    <div class="section-layout">
      <div class="section-nav" data-section-nav="${mode}">
        <label class="section-nav-select"><select data-section-select><optgroup label="Site">${sections.map(option).join('')}</optgroup></select></label>
        <nav class="section-nav-list" aria-label="Settings"><ul>${sections.map(link).join('')}</ul></nav>
      </div>
      <div class="section-body">${mode === 'switch' ? sections.map(panel).join('') : ''}</div>
    </div>
    <a href="${base}?tab=${current}" data-section-discard>Discard</a>
  </form></body>`;
  const dom = new JSDOM(html, { url, runScripts: 'outside-only', pretendToBeVisual: true });
  const { window } = dom;
  window.matchMedia = window.matchMedia || (() => ({ matches: false, addEventListener() {}, addListener() {} }));
  window.eval(source);
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  return window;
}
const state = (w) => {
  const d = w.document;
  return {
    current: Array.from(d.querySelectorAll('[data-section][aria-current="page"]')).map((a) => a.getAttribute('data-section')).join(','),
    shown: Array.from(d.querySelectorAll('[data-panel]')).filter((p) => !p.classList.contains('hidden')).map((p) => p.getAttribute('data-panel')).join(','),
    active: Array.from(d.querySelectorAll('[data-panel].active')).map((p) => p.getAttribute('data-panel')).join(','),
    select: d.querySelector('[data-section-select]').value,
    input: d.querySelector('[data-active-tab-input]').value,
    discard: d.querySelector('[data-section-discard]').getAttribute('href'),
    url: w.location.pathname + w.location.search,
  };
};
const click = (w, el, opts) => {
  const event = new w.MouseEvent('click', Object.assign({ bubbles: true, cancelable: true, button: 0 }, opts || {}));
  el.dispatchEvent(event);
  return event.defaultPrevented;
};

{
  const w = page('switch', 'http://localhost/admin/settings', 'basics');
  const s0 = state(w);
  check('0. it starts on the section the server chose', s0.current === 'basics' && s0.shown === 'basics', JSON.stringify(s0));
  const stopped = click(w, w.document.querySelector('[data-section="smtp"]'));
  const s = state(w);
  check('1. a click switches without loading a page', stopped === true);
  check('1. the panel shows, the others hide', s.shown === 'smtp' && s.active === 'smtp', JSON.stringify(s));
  check('1. the link is current, once', s.current === 'smtp', s.current);
  check('1. the select and active_tab follow', s.select === 'smtp' && s.input === 'smtp', JSON.stringify(s));
  check('2. the address follows', s.url === '/admin/settings?tab=smtp', s.url);
  check('2. Discard points at the section', s.discard === '/admin/settings?tab=smtp', s.discard);

  const sel = w.document.querySelector('[data-section-select]');
  sel.value = 'backup';
  sel.dispatchEvent(new w.Event('change', { bubbles: true }));
  const s3 = state(w);
  check('3. the select switches', s3.shown === 'backup' && s3.current === 'backup' && s3.input === 'backup' && s3.url === '/admin/settings?tab=backup', JSON.stringify(s3));

  const left = click(w, w.document.querySelector('[data-section="basics"]'), { ctrlKey: true });
  check('4. a ctrl click is left to the browser', left === false && state(w).shown === 'backup', JSON.stringify(state(w)));
}

{
  const w = page('switch', 'http://localhost/admin/settings#backup', 'basics');
  const s = state(w);
  check('5. #backup opens Backups', s.shown === 'backup' && s.current === 'backup' && s.input === 'backup' && s.url === '/admin/settings?tab=backup', JSON.stringify(s));
}

{
  const w = page('switch', 'http://localhost/admin/settings?tab=smtp#nothing', 'smtp');
  const s = state(w);
  check('6. an unknown #key changes nothing', s.shown === 'smtp' && s.current === 'smtp' && s.input === 'smtp', JSON.stringify(s));
}

{
  const w = page('links', 'http://localhost/admin/shop-settings?tab=basics', 'basics');
  // jsdom does not navigate; stop the click at the document so it does not try.
  let seen = null;
  w.document.addEventListener('click', (e) => { seen = e.defaultPrevented; e.preventDefault(); });
  click(w, w.document.querySelector('[data-section="smtp"]'));
  check('7. in links mode a click is a plain link', seen === false && state(w).current === 'basics', String(seen));
}

console.log(failed ? `\n${failed} FAILED` : '\nALL PASSED');
process.exit(failed ? 1 : 0);
