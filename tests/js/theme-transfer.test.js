/*
 * Theme > Export, import and reset (public/assets/js/admin-theme-transfer.js, run as shipped in jsdom; the markup is the part of
 * admin/templates/partials/theme-transfer.twig the script reads).
 *
 *   1. all parts       with every part ticked, Export is the plain address (everything)
 *   2. some parts      unticking parts puts the ticked ones in the address as parts[]
 *   3. Branding only   the shortcut ticks Branding alone; All parts ticks them all again
 *   4. none            with nothing ticked, Export cannot be used
 *   5. reset           a reset chip asks first, then posts the section to ?transfer=reset with the form token
 *
 *   node tests/js/theme-transfer.test.js        (after: npm ci --prefix tests/js)
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
const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'admin-theme-transfer.js'), 'utf8');

const parts = ['branding', 'section:header', 'custom_css'];
const html = `<!doctype html><html><body><form>
  <input type="hidden" name="_csrf" value="tok">
  <section data-theme-transfer data-endpoint="/admin/theme">
    ${parts.map((p) => `<label><input type="checkbox" value="${p}" checked data-tt-export-part>${p}</label>`).join('')}
    <button type="button" data-tt-only="branding">Branding only</button><button type="button" data-tt-only="">All parts</button>
    <a href="/admin/theme?transfer=export" data-tt-export>Export</a>
    <input type="file" data-tt-file>
    <div data-tt-preview hidden><p data-tt-summary></p><div data-tt-parts></div><ul data-tt-warnings></ul><p data-tt-extra></p>
      <button type="button" data-tt-apply>Import</button><button type="button" data-tt-cancel>Cancel</button></div>
    <div data-tt-undo-row hidden><button type="button" data-tt-undo>Undo</button></div>
    <button type="button" data-tt-reset="branding:colour">Colour</button>
    <p data-tt-status></p>
    <template data-tt-part><details><summary><input type="checkbox" data-tt-tick><span data-tt-label></span><span data-tt-count></span></summary><table><tbody data-tt-rows></tbody></table></details></template>
  </section></form></body></html>`;

const dom = new JSDOM(html, { url: 'https://example.test/admin/theme?tab=transfer', runScripts: 'outside-only' });
const { window } = dom;
const posts = [];
let confirmAnswer = false;
window.confirm = () => confirmAnswer;
window.fetch = (url, options) => { posts.push({ url, body: options.body }); return new Promise(() => {}); };
window.eval(source);
const doc = window.document;
const link = doc.querySelector('[data-tt-export]');
const box = (value) => doc.querySelector('[data-tt-export-part][value="' + value + '"]');
const change = (el) => el.dispatchEvent(new window.Event('change'));

// 1
check('all parts ticked: the plain export address', link.getAttribute('href') === '/admin/theme?transfer=export', link.getAttribute('href'));
// 2
box('section:header').checked = false; change(box('section:header'));
check('some parts: the ticked ones in the address', link.getAttribute('href') === '/admin/theme?transfer=export&parts[]=branding&parts[]=custom_css', link.getAttribute('href'));
// 3
doc.querySelector('[data-tt-only="branding"]').click();
check('Branding only ticks Branding alone', parts.map((p) => box(p).checked).join() === 'true,false,false' && link.getAttribute('href') === '/admin/theme?transfer=export&parts[]=branding', link.getAttribute('href'));
doc.querySelector('[data-tt-only=""]').click();
check('All parts ticks them all again', parts.every((p) => box(p).checked) && link.getAttribute('href') === '/admin/theme?transfer=export');
// 4
parts.forEach((p) => { box(p).checked = false; });
change(box('branding'));
check('nothing ticked: Export is disabled', link.getAttribute('aria-disabled') === 'true' && link.classList.contains('pointer-events-none'));
// 5
const reset = doc.querySelector('[data-tt-reset]');
reset.click();
check('a reset asks first, and nothing is sent when the answer is no', posts.length === 0, posts.length);
confirmAnswer = true;
reset.click();
const sent = posts[0];
check('then it posts the section with the token', sent && sent.url === '/admin/theme?transfer=reset' && sent.body.get('section') === 'branding:colour' && sent.body.get('_csrf') === 'tok', sent && sent.url);

console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
process.exit(failed === 0 ? 0 : 1);
