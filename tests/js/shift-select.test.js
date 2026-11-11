/*
 * Shift-click ranges in admin lists (public/assets/js/admin.js, run as shipped in jsdom).
 *
 *   1. a range ticks        click row 2, shift-click row 5: rows 2 to 5 are ticked, the others are not
 *   2. a range clears       shift-click back with the box cleared: the range is cleared again
 *   3. counters follow      each changed box sends a change event, so "select all" turns indeterminate or ticked
 *   4. groups stay apart    a box of another name, or a hidden row, is left alone
 *   5. boxes without name   the term list's [data-term-select] boxes work the same way
 *
 *   node tests/js/shift-select.test.js        (after: npm ci --prefix tests/js)
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

const rows = (n, extra) => Array.from({ length: n }, (_, i) =>
  `<tr${extra && extra(i) ? ' hidden' : ''}><td><input type="checkbox" name="selected[]" value="e${i}"></td><td><input type="checkbox" name="other[]" value="o${i}"></td></tr>`).join('');
const terms = (n) => Array.from({ length: n }, (_, i) => `<li data-term-row><input type="checkbox" data-term-select></li>`).join('');

const dom = new JSDOM(`<!doctype html><body>
  <form id="list"><table><thead><tr><th><input type="checkbox" data-select-all="selected[]"></th></tr></thead>
  <tbody>${rows(8, (i) => i === 3)}</tbody></table></form>
  <form id="terms"><ul>${terms(5)}</ul></form>
</body>`, { runScripts: 'outside-only', pretendToBeVisual: true });
const { window } = dom;
window.matchMedia = window.matchMedia || (() => ({ matches: false, addEventListener() {}, addListener() {} }));
window.eval(source);
const doc = window.document;

const boxes = (sel) => Array.from(doc.querySelectorAll(sel));
const list = () => boxes('#list input[name="selected[]"]');
const ticked = (arr) => arr.map((b, i) => (b.checked ? i : null)).filter((i) => i !== null).join(',');
const click = (box, shift) => box.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, shiftKey: !!shift }));
// A real click flips the box before listeners run; MouseEvent on a checkbox in jsdom does the same.
const head = doc.querySelector('[data-select-all]');

click(list()[1]);
click(list()[5], true);
check('1. a range ticks (hidden row 3 left out)', ticked(list()) === '1,2,4,5', ticked(list()));
check('4. a box of another name is left alone', ticked(boxes('#list input[name="other[]"]')) === '', ticked(boxes('#list input[name="other[]"]')));
check('3. select all turns indeterminate', head.indeterminate === true && head.checked === false, `${head.indeterminate}/${head.checked}`);

click(list()[2]);
click(list()[5], true);
check('2. a range clears', ticked(list()) === '1', ticked(list()));

click(list()[0]);
click(list()[7], true);
check('1. a range from the top to the bottom', ticked(list()) === '0,1,2,4,5,6,7', ticked(list()));
check('3. select all stays unticked while a hidden row is unticked', head.checked === false, head.checked);

const t = boxes('#terms [data-term-select]');
click(t[4]);
click(t[1], true);
check('5. term boxes without a name, shift-clicked upwards', ticked(t) === '1,2,3,4', ticked(t));

click(t[0], false);
check('a plain click changes only its own box', ticked(t) === '0,1,2,3,4', ticked(t));

if (failed) { console.log(failed + ' check(s) failed'); process.exit(1); }
console.log('all shift-select checks passed');
