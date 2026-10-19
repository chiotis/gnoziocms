/*
 * The Image block's picture width in the block builder (public/assets/js/admin-blocks.js, run as shipped in jsdom): the chips, the
 * number box and the unit switch of the Design tab, the alignment and "full width on a phone" showing only for a narrower picture,
 * and what is written (an untouched picture writes nothing; the editor reads back what it wrote).
 *
 *   node tests/js/image-width.test.js        (after: npm ci --prefix tests/js)
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
// check(label, actual, expected): the two read as text must be the same.
function check(label, actual, expected) {
  const ok = String(actual) === String(expected);
  if (!ok) { failed++; }
  console.log((ok ? 'ok   ' : 'FAIL ') + label + (ok ? '' : '  ' + String(actual).replace(/\s+/g, ' ').slice(0, 300) + ' expected ' + expected));
}
const js = (name) => fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', name), 'utf8');

const choice = (key, label, def, options) => ({ key, label, type: 'select', default: def, options: options.map((o) => [o, o]) });
const IMAGE = {
  type: 'image', label: 'Image', description: 'One image', preview: '', category: 'Media',
  fields: [
    { key: 'image', label: 'Image', type: 'image' },
    { key: 'image_width', label: 'Picture width', type: 'number', default: '', blank: true, min: 10, max: 2000, units: 'image_width_unit', ranges: { '%': [10, 100], px: [40, 2000] }, presets: [25, 33, 50, 66, 75, 100] },
    choice('image_width_unit', 'Width unit', '%', ['%', 'px']),
    Object.assign(choice('image_align', 'Picture position', 'center', ['left', 'center', 'right']), { needs_size: 'image_width' }),
    { key: 'image_full_mobile', label: 'Full width on a phone', type: 'toggle', default: true, needs_size: 'image_width' }
  ],
  common: [choice('variant', 'Layout', 'wide', ['wide', 'narrow']), choice('tone', 'Tone', 'default', ['default']), choice('width', 'Width', '100', ['100', '50'])],
  design_fields: ['image_width', 'image_width_unit', 'image_align', 'image_full_mobile']
};

function builder(blocks) {
  const html = '<form><input type="hidden" data-block-editor-output><input type="hidden" data-block-editor-flag><div data-block-editor></div>'
    + '<script type="application/json" id="block-editor-data">' + JSON.stringify({ definitions: [IMAGE], blocks, presets: [] }) + '</script></form>';
  const dom = new JSDOM('<!doctype html><body>' + html + '</body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/' });
  const { window } = dom;
  const doc = window.document;
  window.Element.prototype.scrollIntoView = function () {};
  window.eval(js('admin-sortable.js'));
  window.eval(js('admin-panel.js'));
  window.eval(js('admin-blocks.js'));
  doc.querySelector('.bk-toggle').click();
  Array.prototype.filter.call(doc.querySelectorAll('.sp-tabs button'), (b) => b.textContent === 'Design')[0].click();
  const read = () => JSON.parse(doc.querySelector('[data-block-editor-output]').value);
  const chip = (text) => Array.prototype.filter.call(doc.querySelectorAll('.bk-design .bk-size .bk-widths button'), (b) => b.textContent === text)[0];
  const unit = (text) => Array.prototype.filter.call(doc.querySelectorAll('.bk-design .bk-size .bk-seg button'), (b) => b.textContent === text)[0];
  const box = () => doc.querySelector('.bk-design .bk-size-input');
  const typeInto = (value) => { const input = box(); input.value = value; input.dispatchEvent(new window.Event('input', { bubbles: true })); };
  const dependents = () => Array.prototype.filter.call(doc.querySelectorAll('.bk-design .bk-field'), (f) => /Picture position|Full width on a phone/.test(f.textContent) && !f.querySelector('.bk-field'));
  const shown = () => dependents().filter((f) => !f.hidden).length;
  return { window, doc, read, chip, unit, box, typeInto, shown, pressed: () => Array.prototype.filter.call(doc.querySelectorAll('.bk-design .bk-size .bk-widths button'), (b) => b.getAttribute('aria-pressed') === 'true').map((b) => b.textContent) };
}

// ---- the control
let b = builder([{ type: 'image', image: '/a.jpg' }]);
check('the Design tab has the percentage chips, a number box and the unit switch', [b.doc.querySelectorAll('.bk-design .bk-size .bk-widths button').length, !!b.box(), !!b.unit('px')].join(), '6,true,true');
check('the whole column is 100%, shown by the chip', b.pressed().join(), '100%');
check('an untouched block writes nothing for the picture', JSON.stringify(b.read()), JSON.stringify([{ type: 'image', image: '/a.jpg' }]));
check('the position and the phone choice are hidden for the whole column', b.shown(), 0);

b.chip('50%').click();
check('a chip fills the percentage', [b.box().value, b.pressed().join(), b.read()[0].image_width].join(), '50,50%,50');
check('a narrower picture shows its position and phone choice', b.shown(), 2);
b.chip('100%').click();
check('100% empties the box, which is the whole column, and hides the choices', [b.box().value, b.read()[0].image_width, b.shown()].join(), ',,0');

b.typeInto('37');
check('a number in the box is the percentage, with no chip pressed', [b.read()[0].image_width, b.pressed().length].join(), '37,0');
b.unit('px').click();
check('the unit switch turns it into pixels, the chips go away', [b.read()[0].image_width_unit, b.read()[0].image_width, b.doc.querySelector('.bk-design .bk-size .bk-widths').hidden, b.box().max, b.box().min].join(), 'px,40,true,2000,40');
b.typeInto('320');
check('320 px is written with its unit', JSON.stringify([b.read()[0].image_width, b.read()[0].image_width_unit]), '[320,"px"]');
check('in pixels the position and phone choice show', b.shown(), 2);
b.unit('%').click();
check('back to percent: the chips return and 320 px is the whole column', [b.doc.querySelector('.bk-design .bk-size .bk-widths').hidden, JSON.stringify(b.read()[0].image_width), b.read()[0].image_width_unit].join(), 'false,"",%');

// ---- alignment and the phone
b = builder([{ type: 'image', image: '/a.jpg', image_width: 40 }]);
const align = Array.prototype.filter.call(b.doc.querySelectorAll('.bk-design .bk-field'), (f) => /Picture position/.test(f.textContent) && !f.querySelector('.bk-field'))[0];
Array.prototype.filter.call(align.querySelectorAll('button'), (x) => x.textContent === 'right')[0].click();
check('the alignment is written', b.read()[0].image_align, 'right');
Array.prototype.filter.call(align.querySelectorAll('button'), (x) => x.textContent === 'center')[0].click();
check('the middle is the default value', b.read()[0].image_align, 'center');
const phone = b.doc.querySelector('.bk-design input[type=checkbox][id$="image_full_mobile"]');
phone.checked = false;
phone.dispatchEvent(new b.window.Event('change', { bubbles: true }));
check('"full width on a phone" turned off is written', b.read()[0].image_full_mobile, 'false');

// ---- reading it back
b = builder([{ type: 'image', image: '/a.jpg', image_width: 320, image_width_unit: 'px', image_align: 'left' }]);
check('a saved width in pixels shows in the box and on the switch', [b.box().value, b.unit('px').getAttribute('aria-pressed'), b.doc.querySelector('.bk-design .bk-size .bk-widths').hidden].join(), '320,true,true');
check('and is written back unchanged', JSON.stringify(b.read()[0]), JSON.stringify({ type: 'image', image: '/a.jpg', image_width: 320, image_width_unit: 'px', image_align: 'left' }));
b = builder([{ type: 'image', image: '/a.jpg', image_width: 66 }]);
check('a saved percentage presses its chip', b.pressed().join(), '66%');

console.log(failed ? failed + ' FAILED' : 'ALL PASSED');
process.exit(failed ? 1 : 0);
