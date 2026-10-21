/*
 * A gallery field (public/assets/js/admin-gallery-field.js), run as shipped in jsdom on the real markup of
 * admin/templates/partials/fields.twig (drawn by tests/js/render-relation.php, which draws any field): the box of addresses turned
 * into thumbnails, adding from the library (several at a time, none twice, up to the field's max), moving with the buttons and by
 * dragging, removing, the box shown again to type in, and a field inside a row of a list added later. Then the library itself
 * (admin-media-picker.js) in its mode for several pictures: it stays open, marks what is chosen and gives each picture once.
 *
 *   node tests/js/gallery-field.test.js        (after: npm ci --prefix tests/js)
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
const draw = (input) => execFileSync('php', [path.join(__dirname, 'render-relation.php')], { input: JSON.stringify(input) }).toString();

const markup = draw({
  definitions: {
    gallery: { type: 'gallery', label: 'Gallery', max: 4 },
    stops: { type: 'repeater', item_label: 'Stop', fields: { name: { type: 'text' }, photos: { type: 'gallery', label: 'Photos' } } }
  },
  name: 'details',
  values: { gallery: ['/uploads/a.jpg', '/uploads/b.jpg', '/uploads/c.jpg'], stops: [] },
  labels: {}
});
check('the server draws a box of addresses, one on each line, inside the gallery field', /<div class="gal" data-gallery-field data-max="4">\s*<textarea name="details\[gallery\]"[^>]*>\/uploads\/a\.jpg\n\/uploads\/b\.jpg\n\/uploads\/c\.jpg<\/textarea>/.test(markup), markup.slice(0, 600));

const dom = new JSDOM('<!doctype html><body><form>' + markup + '</form></body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/admin/edit' });
const { window } = dom;
const doc = window.document;
// The library, as the picker gives it: what was asked, and a way to pick as a person would.
const library = { calls: [], open(callback, kind, options) { this.calls.push({ kind, options }); this.callback = callback; } };
window.GnozioMediaPicker = library;
window.eval(js('admin.js'));
window.eval(js('admin-gallery-field.js'));
doc.dispatchEvent(new window.Event('DOMContentLoaded'));

const field = doc.querySelector('[data-gallery-field]');
const box = () => field.querySelector('[data-gal-text]');
const shown = (f) => Array.prototype.map.call((f || field).querySelectorAll('[data-gal-item]'), (n) => n.getAttribute('data-url'));
const sent = () => box().value.split('\n').filter(Boolean);
const click = (node) => node.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
const button = (i, which) => field.querySelectorAll('[data-gal-item]')[i].querySelector('[data-gal-' + which + ']');
let changes = 0;
box().addEventListener('change', () => { changes++; });

check('the box is hidden and the pictures are thumbnails, in order', box().hidden && JSON.stringify(shown()) === JSON.stringify(['/uploads/a.jpg', '/uploads/b.jpg', '/uploads/c.jpg']), shown());
check('each picture has its own image and buttons that say which picture they move or remove', field.querySelectorAll('.gal-item img').length === 3 && button(1, 'left').getAttribute('aria-label') === 'Move picture 2 (b.jpg) earlier' && button(1, 'remove').getAttribute('aria-label') === 'Remove picture 2 (b.jpg)');
check('the first cannot move earlier, the last cannot move later', button(0, 'left').disabled && button(2, 'right').disabled && !button(1, 'left').disabled);
check('the count is said', field.querySelector('[data-gal-count]').textContent === '3 pictures');

click(button(0, 'right'));
check('moving a picture later changes the order sent', JSON.stringify(sent()) === JSON.stringify(['/uploads/b.jpg', '/uploads/a.jpg', '/uploads/c.jpg']) && changes === 1, sent());
check('the focus stays on the button of the picture that moved', doc.activeElement === button(1, 'right'), doc.activeElement && doc.activeElement.outerHTML);
click(button(2, 'left'));
check('and earlier', JSON.stringify(sent()) === JSON.stringify(['/uploads/b.jpg', '/uploads/c.jpg', '/uploads/a.jpg']), sent());

// Dragging the last picture onto the first.
const items = field.querySelectorAll('[data-gal-item]');
const drag = (node, type) => { const e = new window.Event(type, { bubbles: true, cancelable: true }); e.dataTransfer = { setData() {}, effectAllowed: '' }; node.dispatchEvent(e); return e; };
drag(items[2], 'dragstart');
const over = drag(items[0], 'dragover');
drag(items[0], 'drop');
drag(items[2], 'dragend');
check('dragging a picture onto another puts it there', over.defaultPrevented && JSON.stringify(sent()) === JSON.stringify(['/uploads/a.jpg', '/uploads/b.jpg', '/uploads/c.jpg']), sent());

click(button(1, 'remove'));
check('removing a picture takes it out of what is sent', JSON.stringify(sent()) === JSON.stringify(['/uploads/a.jpg', '/uploads/c.jpg']) && shown().length === 2, sent());

const add = field.querySelector('[data-gal-add]');
click(add);
const call = library.calls[0];
check('Add pictures opens the library for images, for several, knowing which are chosen', call && call.kind === 'image' && call.options.multiple === true && JSON.stringify(call.options.chosen) === JSON.stringify(['/uploads/a.jpg', '/uploads/c.jpg']), JSON.stringify(call));
library.callback('/uploads/d.jpg');
library.callback('/uploads/a.jpg');
library.callback('/uploads/e.jpg');
check('each picture picked is added at the end, none twice', JSON.stringify(sent()) === JSON.stringify(['/uploads/a.jpg', '/uploads/c.jpg', '/uploads/d.jpg', '/uploads/e.jpg']), sent());
library.callback('/uploads/f.jpg');
check('up to the most the field takes, then Add is switched off', sent().length === 4 && add.disabled && /the most it takes/.test(field.querySelector('[data-gal-count]').textContent), sent());

const toggle = field.querySelector('[data-gal-toggle]');
click(toggle);
check('Edit as text shows the box', !box().hidden && toggle.getAttribute('aria-expanded') === 'true' && toggle.textContent === 'Done');
box().value = '/uploads/z.jpg\n\n/uploads/a.jpg\n/uploads/z.jpg';
box().dispatchEvent(new window.Event('input', { bubbles: true }));
check('typing in it redraws the thumbnails (empty lines and repeats left out)', JSON.stringify(shown()) === JSON.stringify(['/uploads/z.jpg', '/uploads/a.jpg']) && !add.disabled, shown());
click(toggle);
check('Done hides it, and what is sent is tidy', box().hidden && box().value === '/uploads/z.jpg\n/uploads/a.jpg', JSON.stringify(box().value));
click(button(0, 'remove'));
click(button(0, 'remove'));
check('with nothing left: an empty box (the server stores no gallery) and a word that says so', box().value === '' && field.querySelector('[data-gal-count]').textContent === 'No pictures yet.' && doc.activeElement === add);

// A field inside a row of a list that is added while editing.
const list = doc.querySelector('[data-repeater]');
click(list.querySelector('[data-repeater-add]'));
(async () => {
  await new Promise((resolve) => setTimeout(resolve, 20));
  const inner = list.querySelector('[data-repeater-rows] [data-gallery-field]');
  check('a gallery in a row added later becomes thumbnails too, with the row\'s name', inner !== null && inner.querySelector('[data-gal-add]') !== null && /^details\[stops\]\[0\]\[photos\]$/.test(inner.querySelector('textarea').name), inner && inner.querySelector('textarea').name);

  // ---- the library in its mode for several pictures
  const picker = new JSDOM('<!doctype html><head><meta name="media-picker-url" content="/admin/media-picker"></head><body></body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/admin/edit' });
  const w = picker.window;
  if (!w.HTMLDialogElement.prototype.showModal) {
    w.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    w.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new w.Event('close')); };
  }
  const asked = [];
  w.fetch = (url) => {
    asked.push(url);
    const items = ['p1', 'p2', 'p3'].map((n) => ({ url: '/uploads/' + n + '.jpg', thumb: '/uploads/' + n + '.jpg', name: n + '.jpg', kind: 'image', alt: '' }));
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ items, tags: [], page: 1, pages: 1, per_page: 24, total: 3 }) });
  };
  w.eval(js('admin-media-picker.js'));
  const given = [];
  w.GnozioMediaPicker.open((url) => given.push(url), 'image', { multiple: true, chosen: ['/uploads/p2.jpg'] });
  await new Promise((resolve) => setTimeout(resolve, 20));
  const dialog = w.document.querySelector('dialog');
  const tiles = dialog.querySelectorAll('[aria-pressed]');
  check('the library asks for images and says it adds several', /kind=image/.test(asked[0]) && dialog.querySelector('h2').textContent === 'Add images' && Array.prototype.some.call(dialog.querySelectorAll('button'), (b) => b.textContent === 'Done'), dialog.querySelector('h2').textContent);
  check('a picture already chosen is marked', tiles.length === 3 && tiles[1].getAttribute('aria-pressed') === 'true' && tiles[0].getAttribute('aria-pressed') === 'false');
  tiles[0].dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  tiles[2].dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  tiles[1].dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  tiles[0].dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  check('each picture clicked is given once, the dialog stays open', JSON.stringify(given) === JSON.stringify(['/uploads/p1.jpg', '/uploads/p3.jpg']) && dialog.hasAttribute('open') && tiles[0].getAttribute('aria-pressed') === 'true', JSON.stringify(given));
  const single = [];
  w.GnozioMediaPicker.open((url) => single.push(url), 'image');
  await new Promise((resolve) => setTimeout(resolve, 20));
  check('opened for one picture it is as it was: Choose, Close, no marks', /^Choose /.test(dialog.querySelector('h2').textContent) && dialog.querySelectorAll('[aria-pressed]').length === 0 && Array.prototype.some.call(dialog.querySelectorAll('button'), (b) => b.textContent === 'Close'),dialog.querySelector('h2').textContent);
  dialog.querySelectorAll('.grid button')[0].dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  check('and a click picks it and closes the dialog', JSON.stringify(single) === JSON.stringify(['/uploads/p1.jpg']) && !dialog.hasAttribute('open'), JSON.stringify(single));

  console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
  process.exit(failed === 0 ? 0 : 1);
})();
