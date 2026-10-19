/*
 * The parts of a field row of Admin > Content types that belong to a kind of field (public/assets/js/admin-content-types.js, the whole
 * file) in jsdom: the options of a select, the Filter chip that a select and a relation share, and that a kind outside the list hides
 * them again.
 *
 *   node tests/js/content-types-kind.test.js        (after: npm ci --prefix tests/js)
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
  console.log((ok ? 'ok   ' : 'FAIL ') + label + (ok ? '' : '  ' + String(detail === undefined ? '' : detail).replace(/\s+/g, ' ').slice(0, 300)));
}

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'admin-content-types.js'), 'utf8');
const dom = new JSDOM(`<!doctype html><body><form><div data-field-rows>
  <div data-field><select data-kind-select><option value="text" selected>text</option><option value="select">select</option></select>
    <textarea data-only-kind="select" hidden></textarea>
    <label data-only-kind="select relation" hidden><input type="checkbox" name="f[0][filterable]"></label></div>
  <div data-field><span>relation</span>
    <label data-only-kind="select relation"><input type="checkbox" name="f[1][filterable]"></label></div>
</div></form></body>`, { runScripts: 'outside-only' });
dom.window.eval(source);
const $ = (s) => dom.window.document.querySelector(s);
const change = (select, value) => { select.value = value; select.dispatchEvent(new dom.window.Event('change', { bubbles: true })); };
const select = $('[data-kind-select]');
const row = $('[data-field]');
const options = row.querySelector('textarea');
const chip = row.querySelector('label');

change(select, 'select');
check('a select shows its options and the Filter chip', !options.hidden && !chip.hidden);
change(select, 'text');
check('a text shows neither', options.hidden && chip.hidden);
change(select, 'select');
change(select, 'text');
check('and they follow the kind each time it changes', options.hidden && chip.hidden);
check('a chip shared by a select and a relation names both kinds, and a relation row (no kind to choose) keeps it', chip.getAttribute('data-only-kind') === 'select relation' && !dom.window.document.querySelectorAll('[data-field]')[1].querySelector('label').hidden);

console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
process.exit(failed === 0 ? 0 : 1);
