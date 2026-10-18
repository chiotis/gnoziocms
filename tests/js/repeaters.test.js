/*
 * Lists of rows with a list in each row (a repeater in a repeater), in both editors, run as shipped in jsdom:
 *
 *   1. the form of a content type or of theme settings: the real fields.twig markup (drawn by tests/js/render-repeater.php) and
 *      public/assets/js/admin.js. Adding, removing and moving rows of either list renames only what belongs to that list, so the
 *      names the browser sends are the rows in the order shown; each list keeps its own maximum.
 *   2. the block builder (public/assets/js/admin-blocks.js): an inner list inside an open row, its rows added, moved and removed,
 *      and the saved blocks.
 *
 *   node tests/js/repeaters.test.js        (after: npm ci --prefix tests/js)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

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
  console.log((ok ? 'ok   ' : 'FAIL ') + label + (ok ? '' : '  ' + String(detail === undefined ? '' : detail).replace(/\s+/g, ' ').slice(0, 400)));
}
const js = (name) => fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', name), 'utf8');

/* 1. The server-rendered form ------------------------------------------------------------------- */

const definitions = {
  steps: { type: 'repeater', item_label: 'Step', fields: {
    title: { type: 'text' },
    tools: { type: 'repeater', item_label: 'Tool', max: 2, fields: { name: { type: 'text' } } }
  } }
};
const markup = execFileSync('php', [path.join(__dirname, 'render-repeater.php')], {
  input: JSON.stringify({ definitions, name: 'details', values: { steps: [{ title: 'A', tools: [{ name: 'x' }, { name: 'y' }] }, { title: 'B', tools: [] }] } })
}).toString();

const dom = new JSDOM('<!doctype html><body><form>' + markup + '</form></body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/admin/edit' });
const { window } = dom;
const doc = window.document;
window.eval(js('admin.js'));
doc.dispatchEvent(new window.Event('DOMContentLoaded'));

/** What the form would send, as "name=value" in order (the template's fields are not part of the form). */
const sent = () => Array.prototype.filter.call(doc.querySelector('form').elements, (c) => c.name).map((c) => c.name + '=' + c.value);
const steps = () => doc.querySelector('[data-repeater-name="details[steps]"]');
const stepRows = () => Array.prototype.filter.call(steps().querySelector(':scope > [data-repeater-rows]').children, (n) => n.matches('[data-repeater-row]'));
const toolsOf = (row) => row.querySelector('[data-repeater]');
const own = (box, selector) => box.querySelector(':scope > ' + selector);
const toolButton = (row, index, selector) => toolsOf(row).querySelectorAll(':scope > [data-repeater-rows] > [data-repeater-row]')[index].querySelector(selector);
const click = (node) => node.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

check('the stored rows are sent in order, each inner row under its own row', JSON.stringify(sent()) === JSON.stringify([
  'details[steps]=', 'details[steps][0][title]=A', 'details[steps][0][tools]=', 'details[steps][0][tools][0][name]=x', 'details[steps][0][tools][1][name]=y',
  'details[steps][1][title]=B', 'details[steps][1][tools]='
]), sent());
check('an inner list that is full cannot take another row, the other one can', own(toolsOf(stepRows()[0]), '[data-repeater-add]').disabled && !own(toolsOf(stepRows()[1]), '[data-repeater-add]').disabled);
check('the rows are numbered, each list on its own', stepRows()[1].querySelector('[data-repeater-title]').textContent === 'Step 2' && toolsOf(stepRows()[0]).querySelectorAll('[data-repeater-title]')[1].textContent === 'Tool 2');

click(own(toolsOf(stepRows()[1]), '[data-repeater-add]'));
toolsOf(stepRows()[1]).querySelector('input[name$="[name]"]').value = 'z';
check('a row added to an inner list is named after its row, the outer rows unchanged', sent().includes('details[steps][1][tools][0][name]=z') && sent().filter((s) => /\[title\]/.test(s)).join() === 'details[steps][0][title]=A,details[steps][1][title]=B', sent());
check('and the template of the inner list still has its own placeholder', toolsOf(stepRows()[1]).querySelector(':scope > template').innerHTML.includes('details[steps][1][tools][__INDEX2__][name]'));

click(stepRows()[1].querySelector('[data-repeater-up]'));
check('moving an outer row up renames it and its inner rows', JSON.stringify(sent()) === JSON.stringify([
  'details[steps]=', 'details[steps][0][title]=B', 'details[steps][0][tools]=', 'details[steps][0][tools][0][name]=z',
  'details[steps][1][title]=A', 'details[steps][1][tools]=', 'details[steps][1][tools][0][name]=x', 'details[steps][1][tools][1][name]=y'
]), sent());
check('the moved row\'s inner list and its template follow it', toolsOf(stepRows()[0]).getAttribute('data-repeater-name') === 'details[steps][0][tools]' && toolsOf(stepRows()[0]).querySelector(':scope > template').innerHTML.includes('details[steps][0][tools][__INDEX2__][name]'));
click(own(toolsOf(stepRows()[0]), '[data-repeater-add]'));
check('a row added after the move gets the new place of its row', sent().includes('details[steps][0][tools][1][name]=') && own(toolsOf(stepRows()[0]), '[data-repeater-add]').disabled, sent());

click(toolButton(stepRows()[1], 0, '[data-repeater-down]'));
check('moving an inner row moves only that list\'s rows', sent().filter((s) => s.startsWith('details[steps][1][tools][')).join() === 'details[steps][1][tools][0][name]=y,details[steps][1][tools][1][name]=x' && stepRows()[1].querySelector('input[name$="[title]"]').value === 'A', sent());
click(toolButton(stepRows()[1], 0, '[data-repeater-remove]'));
check('removing an inner row leaves its row and the other rows', sent().filter((s) => s.startsWith('details[steps][1]')).join() === 'details[steps][1][title]=A,details[steps][1][tools]=,details[steps][1][tools][0][name]=x' && stepRows().length === 2, sent());

click(own(steps(), '[data-repeater-add]'));
const fresh = stepRows()[2];
check('a new outer row comes with an empty inner list of its own', toolsOf(fresh).getAttribute('data-repeater-name') === 'details[steps][2][tools]' && toolsOf(fresh).querySelectorAll('[data-repeater-row]').length === 0 && sent().includes('details[steps][2][tools]='), sent());
click(own(toolsOf(fresh), '[data-repeater-add]'));
check('and its inner list adds rows under it', sent().includes('details[steps][2][tools][0][name]='), sent());
click(stepRows()[0].querySelector('[data-repeater-remove]'));
check('removing an outer row renumbers the others with everything inside them', JSON.stringify(sent()) === JSON.stringify([
  'details[steps]=', 'details[steps][0][title]=A', 'details[steps][0][tools]=', 'details[steps][0][tools][0][name]=x',
  'details[steps][1][title]=', 'details[steps][1][tools]=', 'details[steps][1][tools][0][name]='
]), sent());
check('no name is left with a placeholder', !sent().some((s) => s.includes('__INDEX')));

/* 2. The block builder -------------------------------------------------------------------------- */

const DEFS = [{
  type: 'slider', label: 'Slider', description: 'Slider', preview: '', category: 'Openers',
  fields: [
    { key: 'slides', label: 'Slides', type: 'repeater', item_label: 'Slide', fields: [
      { key: 'title', label: 'Title', type: 'text' },
      { key: 'buttons', label: 'Buttons', type: 'repeater', item_label: 'Button', max: 2, fields: [{ key: 'label', label: 'Label', type: 'text' }, { key: 'style', label: 'Style', type: 'select', default: 'primary', options: [['primary', 'Primary'], ['ghost', 'Ghost']] }] }
    ] }
  ],
  common: [{ key: 'variant', label: 'Layout', type: 'select', default: 'default', options: [['default', 'Default']] }],
  design_fields: []
}];
const bdom = new JSDOM('<!doctype html><body><form><input type="hidden" data-block-editor-output><input type="hidden" data-block-editor-flag><div data-block-editor></div>'
  + '<script type="application/json" id="block-editor-data">' + JSON.stringify({ definitions: DEFS, blocks: [{ type: 'slider', slides: [{ title: 'One', buttons: [{ label: 'Go' }] }, { title: 'Two' }] }], presets: [] }) + '</script></form></body>',
  { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/admin/edit' });
const bw = bdom.window;
const bd = bw.document;
bw.Element.prototype.scrollIntoView = function () {};
bw.eval(js('admin-sortable.js'));
bw.eval(js('admin-blocks.js'));
const saved = () => JSON.parse(bd.querySelector('[data-block-editor-output]').value);
const card = bd.querySelector('.bk-card .bk-toggle');
if (card.getAttribute('aria-expanded') !== 'true') card.click();
const outerRows = () => bd.querySelectorAll('.bk-card .bk-rows')[0].querySelectorAll(':scope > .bk-row');
outerRows()[0].querySelector('.bk-row-toggle').click();
const inner = () => outerRows()[0].querySelector('.bk-row-body .bk-rep');
check('an open row shows its inner list with its rows', inner() !== null && inner().querySelectorAll('.bk-row').length === 1 && inner().querySelector('.bk-row-title').textContent === 'Go', inner() && inner().innerHTML.slice(0, 200));
check('an inner list of one row is a list of its own, so the outer list never takes its row', inner().querySelector('.bk-rows').hasAttribute('data-sortable'));
check('the row of the outer list is not titled by its inner list', outerRows()[0].querySelector('.bk-row-title').textContent === 'One');
Array.prototype.find.call(inner().querySelectorAll('button'), (b) => /Add button/.test(b.textContent)).click();
check('adding an inner row saves it under its slide, with the defaults of its fields', JSON.stringify(saved()[0].slides[0].buttons) === '[{"label":"Go"},{"style":"primary"}]', JSON.stringify(saved()[0].slides));
const label = inner().querySelectorAll('.bk-row')[1].querySelector('input');
label.value = 'More';
label.dispatchEvent(new bw.Event('input', { bubbles: true }));
check('typing in an inner row saves it', saved()[0].slides[0].buttons[1].label === 'More', JSON.stringify(saved()[0].slides[0]));
const addInner = Array.prototype.find.call(inner().querySelectorAll('button'), (b) => /Add button/.test(b.textContent));
check('a full inner list cannot take another row', addInner.disabled === true);
const innerGrips = () => inner().querySelectorAll('.bk-row .bk-grip');
innerGrips()[1].dispatchEvent(new bw.MouseEvent('click', { bubbles: true, detail: 0 }));
innerGrips()[1].dispatchEvent(new bw.KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true }));
innerGrips()[1].dispatchEvent(new bw.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
check('moving an inner row reorders only that list', JSON.stringify(saved()[0].slides.map((s) => [s.title, (s.buttons || []).map((b) => b.label)])) === '[["One",["More","Go"]],["Two",[]]]', JSON.stringify(saved()[0].slides));
inner().querySelectorAll('.bk-row')[0].querySelector('.bk-row-actions button').click();
check('removing an inner row leaves the slide and the other row', JSON.stringify(saved()[0].slides.map((s) => [s.title, (s.buttons || []).map((b) => b.label)])) === '[["One",["Go"]],["Two",[]]]', JSON.stringify(saved()[0].slides));
check('the outer list still has its two rows', outerRows().length === 2);

console.log(failed ? '\n' + failed + ' FAILED' : '\nALL PASSED');
process.exit(failed ? 1 : 0);
