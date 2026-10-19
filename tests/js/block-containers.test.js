/*
 * Container rows in the block builder (public/assets/js/admin-blocks.js with admin-sortable.js), both run as shipped in jsdom. jsdom has
 * no layout, so the boxes of the cards and columns are given by hand (a stack of cards, and a container whose columns sit side by side).
 *
 *   1. what is written        blocks without a container come back as they went in; a container is written as settings and columns
 *   2. moving blocks          by the pointer between columns, into and out of a container; a container is not dropped into a column
 *   3. the keyboard           Shift and the arrow keys carry a block to the next column or the top level, and say where it is
 *   4. wrap and unwrap        the "…" menu: a block in a container of its own, a container taken apart in order
 *   5. columns and settings   a preset, adding and removing columns (the blocks are kept), the limit of six, the Width of a block in a column
 *   6. a save round trip      what is written is what the builder shows again, and writing it twice changes nothing
 *
 *   node tests/js/block-containers.test.js        (after: npm ci --prefix tests/js)
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
const js = (name) => fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', name), 'utf8');
const sortableSource = js('admin-sortable.js');
const panelSource = js('admin-panel.js');
const blocksSource = js('admin-blocks.js');

const select = (key, label, def, options) => ({ key, label, type: 'select', default: def, options: options.map((o) => [o, o]) });
const DEFS = [{
  type: 'text', label: 'Text', description: 'Text', preview: '', category: 'Content',
  fields: [{ key: 'heading', label: 'Heading', type: 'text' }],
  common: [select('variant', 'Layout', 'default', ['default']), select('tone', 'Tone', 'default', ['default']), select('width', 'Width', '100', ['100', '50'])],
  design_fields: []
}, {
  type: 'cta', label: 'Call to action', description: 'CTA', preview: '', category: 'Convert',
  fields: [{ key: 'heading', label: 'Heading', type: 'text' }],
  common: [select('variant', 'Layout', 'default', ['default']), select('width', 'Width', '100', ['100', '50'])],
  design_fields: []
}];
const CONTAINER = {
  type: 'container', label: 'Container', description: 'A row', max_columns: 6,
  presets: [{ id: '50-50', label: '50 / 50', widths: ['1/2', '1/2'] }, { id: '3x33', label: '3 × 33', widths: ['1/3', '1/3', '1/3'] }, { id: '25-50-25', label: '25 / 50 / 25', widths: ['1/4', '1/2', '1/4'] }],
  settings: [select('direction', 'Direction', 'row', ['row', 'row-reverse']), select('gap', 'Column gap', 'm', ['none', 'm', 'l']), select('stack_phone', 'Phone', 'one', ['row', 'two', 'one']), select('stack_desktop', 'Desktop', 'row', ['row', 'two', 'one']), select('stack_tablet', 'Tablet', 'auto', ['auto', 'row', 'two', 'one']), select('justify', 'Justify', 'start', ['start', 'center']), select('align', 'Align', 'stretch', ['stretch']), select('align_content', 'Align content', 'stretch', ['stretch']), select('wrap', 'Wrap', 'wrap', ['wrap', 'nowrap']), select('row_gap', 'Row gap', 'm', ['none', 'm']), select('tone', 'Background', 'default', ['default', 'muted']), { key: 'image', label: 'Picture', type: 'image', default: '' }, select('overlay', 'Overlay', 'none', ['none', 'dark']), select('inner', 'Inner width', 'container', ['container', 'narrow']), select('spacing', 'Spacing', 'default', ['default']), select('min_height', 'Min height', 'none', ['none']), { key: 'anchor', label: 'Anchor', type: 'text', default: '' }, { key: 'class', label: 'Class', type: 'text', default: '' }, { key: 'hidden', label: 'Hide', type: 'toggle', default: false }, { key: 'reverse_phone', label: 'Reverse', type: 'toggle', default: false }],
  columns: [{ key: 'width', label: 'Width', type: 'text', default: 'fill' }, { key: 'min_width', label: 'Min', type: 'text', default: '' }, { key: 'max_width', label: 'Max', type: 'text', default: '' }, { key: 'grow', label: 'Grow', type: 'number', default: '' }, { key: 'shrink', label: 'Shrink', type: 'number', default: '' }, { key: 'basis', label: 'Basis', type: 'text', default: '' }, select('align_self', 'Align self', 'auto', ['auto', 'start']), select('valign', 'Content', 'top', ['top', 'center']), { key: 'order', label: 'Order', type: 'number', default: 0 }, select('padding', 'Padding', 'none', ['none', 'm']), select('tone', 'Background', 'default', ['default', 'muted']), select('radius', 'Corners', 'none', ['none', 'm'])]
};

function builder(blocks) {
  const html = '<form><input type="hidden" data-block-editor-output><input type="hidden" data-block-editor-flag><div data-block-editor></div>'
    + '<script type="application/json" id="block-editor-data">' + JSON.stringify({ definitions: DEFS, container: CONTAINER, blocks, presets: [] }) + '</script></form>';
  const dom = new JSDOM('<!doctype html><body>' + html + '</body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/' });
  const { window } = dom;
  const doc = window.document;
  // The boxes: top-level cards one under the other (a container is tall and holds its columns side by side, each 330 wide).
  const top = () => Array.prototype.filter.call(doc.querySelectorAll('.bk-card'), (c) => !c.parentElement.closest('.bk-card'));
  const boxOf = (node) => {
    if (node.matches('.bk-list')) return { left: 0, top: 0, width: 700, height: 3000 };
    if (node.matches('.bk-card')) {
      const owner = node.parentElement.closest('.bk-card');
      if (!owner) {
        let y = 0;
        for (const card of top()) {
          if (card === node) return { left: 0, top: y, width: 700, height: card.classList.contains('bk-container') ? 400 : 50 };
          y += (card.classList.contains('bk-container') ? 400 : 50) + 10;
        }
      }
      const col = node.closest('.bk-col');
      const at = Array.prototype.indexOf.call(col.querySelectorAll('.bk-card'), node);
      const c = boxOf(col);
      return { left: c.left + 5, top: c.top + 30 + at * 60, width: 320, height: 50 };
    }
    if (node.matches('.bk-col')) {
      const card = node.closest('.bk-container');
      const c = boxOf(card);
      const at = Array.prototype.indexOf.call(node.parentElement.querySelectorAll('.bk-col'), node);
      return { left: 10 + at * 340, top: c.top + 60, width: 330, height: 300 };
    }
    return null;
  };
  window.Element.prototype.getBoundingClientRect = function () {
    const r = boxOf(this) || { left: 0, top: 0, width: 0, height: 0 };
    return { left: r.left, top: r.top, width: r.width, height: r.height, right: r.left + r.width, bottom: r.top + r.height, x: r.left, y: r.top };
  };
  window.Element.prototype.scrollIntoView = function () {};
  window.eval(sortableSource);
  window.eval(panelSource);
  window.eval(blocksSource);
  const read = () => JSON.parse(doc.querySelector('[data-block-editor-output]').value);
  const shape = (list) => list.map((b) => b.type === 'container' ? ['container', b.columns.map((c) => (c.blocks || []).map((x) => x.heading || x.type))] : (b.heading || b.type));
  return { window, doc, read, out: () => shape(read()), boxOf };
}

const T = (heading, extra) => Object.assign({ type: 'text', heading }, extra || {});
const container = (columns, settings) => ({ type: 'container', settings: settings || {}, columns: columns.map((col) => Array.isArray(col) ? { settings: {}, blocks: col } : col) });

function pointer(window, node, type, x, y) {
  const event = new window.MouseEvent(type, { bubbles: true, cancelable: true, clientX: x, clientY: y, button: 0 });
  Object.defineProperty(event, 'pointerId', { value: 1 });
  Object.defineProperty(event, 'pointerType', { value: 'mouse' });
  Object.defineProperty(event, 'isPrimary', { value: true });
  node.dispatchEvent(event);
}
function drag(b, grip, x, y) {
  const r = grip.getBoundingClientRect();
  pointer(b.window, grip, 'pointerdown', r.left + 4, r.top + 4);
  pointer(b.window, b.doc, 'pointermove', x, y);
  pointer(b.window, b.doc, 'pointerup', x, y);
}
function key(b, node, k, extra) {
  node.dispatchEvent(new b.window.KeyboardEvent('keydown', Object.assign({ key: k, bubbles: true, cancelable: true }, extra || {})));
}
const said = (b) => b.doc.querySelector('body > [aria-live]').textContent.replace(/\u00a0/g, '').trim();
const colGrip = (b, c, k) => b.doc.querySelectorAll('.bk-col')[c].querySelectorAll('.bk-card')[k].querySelector('.bk-grip');
const topGrips = () => null;

function menuItem(b, card, label) {
  card.querySelector('[data-action="more"]').click();
  const item = Array.prototype.find.call(card.querySelectorAll('.bk-menu-item'), (n) => n.textContent.indexOf(label) !== -1);
  return item;
}

function written() {
  // 1. what is written
  const plain = [T('One'), T('Two', { width: '50', tone: 'muted' }), { type: 'cta', heading: 'Go' }];
  let b = builder(plain);
  check('blocks without a container come out as they went in', JSON.stringify(b.read()) === JSON.stringify(plain), JSON.stringify(b.read()));

  const nested = [T('Before'), container([[T('A'), { type: 'cta', heading: 'B' }], [T('C')]], { gap: 'l' }), T('After')];
  b = builder(nested);
  check('a container is read and written back with its settings, columns and blocks', JSON.stringify(b.read()) === JSON.stringify(nested), JSON.stringify(b.read()));
  check('and drawn: a card with its columns side by side and the blocks in them, always (not only when opened)', b.doc.querySelectorAll('.bk-container').length === 1 && b.doc.querySelectorAll('.bk-container .bk-col').length === 2, b.doc.querySelectorAll('.bk-col').length);
  check('each column is a list of its blocks', b.doc.querySelectorAll('.bk-col').length === 2 && b.doc.querySelectorAll('.bk-col')[0].querySelectorAll('.bk-card').length === 2 && b.doc.querySelectorAll('.bk-col')[1].querySelectorAll('.bk-card').length === 1);
  check('a card says where it stands (the preview finds it by that)', b.doc.querySelector('.bk-container').getAttribute('data-block-path') === '1' && b.doc.querySelectorAll('.bk-col')[0].querySelectorAll('.bk-card')[1].getAttribute('data-block-path') === '1.0.1');
  b.doc.querySelector('.bk-container .bk-toggle').click();
  check('a click on its header opens the container\'s settings in the panel, Layout and Design', !b.doc.querySelector('#bk-settings').hidden && b.doc.querySelector('#bk-settings .sp-title').textContent === '2. Container' && Array.prototype.map.call(b.doc.querySelectorAll('#bk-settings [role=tab]'), (n) => n.textContent).join('|') === 'Layout|Design', b.doc.querySelector('#bk-settings').outerHTML.slice(0, 300));
  b.doc.querySelectorAll('.bk-col-title')[1].click();
  check('a click on a column\'s title opens the container with that column chosen', /Column 2 of 2/.test(b.doc.querySelector('#bk-settings .sp-body').textContent));

  b = builder([container([])]);
  check('a container with no columns is given one', JSON.stringify(b.read()) === JSON.stringify([{ type: 'container', settings: {}, columns: [{ settings: {}, blocks: [] }] }]), JSON.stringify(b.read()));
  b = builder([container([[T('x'), container([[T('deep')]])]])]);
  check('a container inside a column is not kept (one level)', JSON.stringify(b.out()) === '[["container",[["x"]]]]', JSON.stringify(b.out()));
}

function pointerMoves() {
  // 2. moving blocks
  let b = builder([T('Top'), container([[T('A'), T('B')], [T('C')]]), T('After')]);
  let changes = 0;
  b.doc.addEventListener('bk:change', () => { changes++; });
  const target = b.boxOf(b.doc.querySelectorAll('.bk-col')[1]);
  drag(b, colGrip(b, 0, 0), target.left + 100, target.top + 200); // A to the second column, below C
  check('a block dragged to another column lands there, in place', JSON.stringify(b.out()) === JSON.stringify(['Top', ['container', [['B'], ['C', 'A']]], 'After']), JSON.stringify(b.out()));
  check('and the builder tells the page', changes > 0, changes);
  check('what was said names the column and the place', /Block Text moved to column 2 of 2 in container 1, position 2 of 2/.test(said(b)), said(b));

  // out to the top level: below the last top-level card
  const last = b.boxOf(b.doc.querySelectorAll('.bk-list > .bk-card')[2]);
  drag(b, colGrip(b, 1, 0), 300, last.top + 45);
  check('a block dragged out of the container lands in the top-level list', JSON.stringify(b.out()) === JSON.stringify(['Top', ['container', [['B'], ['A']]], 'After', 'C']), JSON.stringify(b.out()));

  // in from the top level
  const col0 = b.boxOf(b.doc.querySelectorAll('.bk-col')[0]);
  drag(b, b.doc.querySelectorAll('.bk-list > .bk-card')[0].querySelector('.bk-grip'), col0.left + 100, col0.top + 250);
  check('and a top-level block dragged onto a column goes into it', JSON.stringify(b.out()) === JSON.stringify([['container', [['B', 'Top'], ['A']]], 'After', 'C']), JSON.stringify(b.out()));

  // a container is not dropped into a column
  b = builder([container([[T('A')], [T('B')]]), container([[T('X')]]), T('Last')]);
  b.doc.querySelectorAll('.bk-container .bk-toggle')[0].click();
  const first = b.doc.querySelectorAll('.bk-list > .bk-card')[0];
  const second = b.doc.querySelectorAll('.bk-list > .bk-card')[1];
  const colBox = b.boxOf(first.querySelectorAll('.bk-col')[1]);
  drag(b, second.querySelector('.bk-grip'), colBox.left + 100, colBox.top + 100);
  check('a container dropped on a column stays at the top level (it is never inside a column)', b.out().every((e) => Array.isArray(e) ? e[1].every((col) => col.every((x) => typeof x === 'string')) : true) && b.read().filter((x) => x.type === 'container').length === 2, JSON.stringify(b.out()));
  check('and no column holds a container', b.read().every((x) => x.type !== 'container' || x.columns.every((c) => (c.blocks || []).every((y) => y.type !== 'container'))));
}

function keyboardMoves() {
  // 3. the keyboard
  const b = builder([T('Top'), container([[T('A'), T('B')], [T('C')]]), T('After')]);
  let grip = colGrip(b, 0, 0);
  grip.focus();
  grip.dispatchEvent(new b.window.MouseEvent('click', { bubbles: true, detail: 0 }));
  check('Space picks a block of a column up', /picked up/.test(said(b)), said(b));
  key(b, grip, 'ArrowRight', { shiftKey: true });
  check('Shift and the right arrow says the block is in the next column', /now in column 2 of 2 in container 1, position 1 of 2/.test(said(b)), said(b));
  key(b, grip, 'ArrowDown');
  key(b, grip, 'Enter');
  check('dropped there, it is in the second column after C', JSON.stringify(b.out()) === JSON.stringify(['Top', ['container', [['B'], ['C', 'A']]], 'After']), JSON.stringify(b.out()));
  check('what was said names the column', /Block Text moved to column 2 of 2 in container 1, position 2 of 2/.test(said(b)), said(b));
  check('the grip of the moved block has the focus', b.doc.activeElement && b.doc.activeElement.classList.contains('bk-grip') && b.doc.activeElement.closest('.bk-col') === b.doc.querySelectorAll('.bk-col')[1]);

  grip = colGrip(b, 1, 1);
  grip.focus();
  grip.dispatchEvent(new b.window.MouseEvent('click', { bubbles: true, detail: 0 }));
  key(b, grip, 'ArrowRight', { shiftKey: true });
  check('one more step goes round to the top level, and says so', /now in the top level, position 2 of 4/.test(said(b)), said(b));
  key(b, grip, 'Enter');
  check('dropped there, the block is at the top level', JSON.stringify(b.out()) === JSON.stringify(['Top', 'A', ['container', [['B'], ['C']]], 'After']), JSON.stringify(b.out()));

  // Escape puts it back
  grip = colGrip(b, 0, 0);
  grip.focus();
  grip.dispatchEvent(new b.window.MouseEvent('click', { bubbles: true, detail: 0 }));
  key(b, grip, 'ArrowRight', { shiftKey: true });
  b.doc.dispatchEvent(new b.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
  check('Escape puts it back where it was', JSON.stringify(b.out()) === JSON.stringify(['Top', 'A', ['container', [['B'], ['C']]], 'After']) && /Move cancelled/.test(said(b)), said(b));
}

function convert() {
  // 4. wrap and unwrap
  const b = builder([T('One'), T('Two'), T('Three')]);
  const cards = () => b.doc.querySelectorAll('.bk-list > .bk-card');
  menuItem(b, cards()[1], 'Wrap in container').click();
  check('Wrap in container puts the block in a container of one column, where it was', JSON.stringify(b.out()) === JSON.stringify(['One', ['container', [['Two']]], 'Three']), JSON.stringify(b.out()));
  check('and says so', /wrapped in a new container at position 2 of 3/.test(said(b)), said(b));
  check('the new container\'s row has the focus', b.doc.activeElement === b.doc.querySelector('.bk-container > .bk-head .bk-toggle'));
  check('a block in a column offers no Wrap but Move out of the container', menuItem(b, b.doc.querySelector('.bk-col .bk-card'), 'Move out of the container') !== undefined && menuItem(b, b.doc.querySelector('.bk-col .bk-card'), 'Wrap in container') === undefined);
  menuItem(b, b.doc.querySelector('.bk-col .bk-card'), 'Move out of the container').click();
  check('Move out of the container puts the block after it', JSON.stringify(b.out()) === JSON.stringify(['One', ['container', [[]]], 'Two', 'Three']), JSON.stringify(b.out()));

  const c = builder([T('Top'), container([[T('A'), T('B')], [T('C')], [T('D')]]), T('Last')]);
  const card = c.doc.querySelectorAll('.bk-list > .bk-card')[1];
  menuItem(c, card, 'Unwrap').click();
  check('Unwrap puts the blocks of the container at the top level in order, column by column', JSON.stringify(c.out()) === JSON.stringify(['Top', 'A', 'B', 'C', 'D', 'Last']), JSON.stringify(c.out()));
  check('and says how many', /Container unwrapped: 4 blocks are now at the top level/.test(said(c)), said(c));
}

function columns() {
  // 5. columns and settings
  const b = builder([container([[T('A')], [T('B')], [T('C')]])]);
  b.doc.querySelector('.bk-container .bk-toggle').click();
  const chips = Array.prototype.filter.call(b.doc.querySelectorAll('.bk-preset'), (n) => n.textContent.indexOf('50 / 50') !== -1);
  chips[0].click();
  const after = b.read()[0];
  check('a preset sets the number of columns and their widths, keeping the blocks of the columns that go', after.columns.length === 2 && after.columns[0].settings.width === '1/2' && after.columns[1].settings.width === '1/2' && JSON.stringify(after.columns[1].blocks.map((x) => x.heading)) === '["B","C"]', JSON.stringify(after));
  const plus = () => b.doc.querySelector('#bk-settings [aria-label="Add a column"]');
  for (let i = 0; i < 8; i++) { if (!plus().disabled) plus().click(); }
  check('at most six columns', b.read()[0].columns.length === 6 && plus().disabled, b.read()[0].columns.length);
  b.doc.querySelector('#bk-settings [aria-label="Remove the last column"]').click();
  check('and one less when asked', b.read()[0].columns.length === 5);
  check('the list draws the columns again as they change', b.doc.querySelectorAll('.bk-container .bk-col').length === 5);
  b.doc.querySelector('[aria-label="Remove column 1"]').click();
  check('removing a column moves its blocks to the neighbour (the next one for the first), none is lost', JSON.stringify(b.out()) === JSON.stringify([['container', [['A', 'B', 'C'], [], [], []]]]), JSON.stringify(b.out()));
  
  // the Width of a block is not offered inside a column
  const w = builder([T('Alone'), container([[T('In')]])]);
  w.doc.querySelector('.bk-col .bk-card .bk-toggle').click();
  w.doc.querySelector('#bk-settings [data-tab="design"]').click();
  const inColumn = w.doc.querySelector('#bk-settings .bk-widths') === null;
  check('the panel says where a block of a column is', w.doc.querySelector('#bk-settings .sp-crumbs').textContent === 'Container 2 › Column 1', w.doc.querySelector('#bk-settings .sp-crumbs').textContent);
  w.doc.querySelectorAll('.bk-list > .bk-card')[0].querySelector('.bk-toggle').click();
  check('a block in a column has no Width, a block at the top level has (the Design tab stays chosen)', inColumn && w.doc.querySelector('#bk-settings [data-tab="design"]').getAttribute('aria-selected') === 'true' && w.doc.querySelector('#bk-settings .bk-widths') !== null);

  // settings are written, and a hidden container says so
  const s = builder([container([[T('A')]])]);
  s.doc.querySelector('.bk-container .bk-toggle').click();
  s.doc.querySelector('#bk-settings [data-tab="design"]').click();
  const gap = Array.prototype.find.call(s.doc.querySelectorAll('#bk-settings .bk-seg button'), (n) => n.getAttribute('aria-label') === 'Medium' || n.textContent === 'L');
  if (gap) gap.click();
  s.doc.querySelector('.bk-container [aria-label="Hide container from the page"]').click();
  check('a setting is written in the container\'s settings, a hidden container is marked', s.read()[0].settings.hidden === true, JSON.stringify(s.read()[0].settings));
}

function roundTrip() {
  // 6. a save round trip
  const b = builder([T('Top'), container([[T('A')], [T('B'), { type: 'cta', heading: 'Go' }]], { gap: 'l', justify: 'center' }), T('Last', { width: '50' })]);
  const target = b.boxOf(b.doc.querySelectorAll('.bk-col')[0]);
  drag(b, colGrip(b, 1, 0), target.left + 100, target.top + 200);
  const saved = b.read();
  const again = builder(saved);
  check('what is written is what the builder reads back', JSON.stringify(again.read()) === JSON.stringify(saved), JSON.stringify(again.read()));
  check('and the same after another round', JSON.stringify(builder(again.read()).read()) === JSON.stringify(saved));
  check('only the order and the columns changed: the settings are as they were', saved[1].settings.gap === 'l' && saved[1].settings.justify === 'center' && saved[2].width === '50', JSON.stringify(saved));
  check('no editor-only state is written', JSON.stringify(saved).indexOf('_id') === -1 && JSON.stringify(saved).indexOf('_open') === -1);
}

written();
pointerMoves();
keyboardMoves();
convert();
columns();
roundTrip();
console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
process.exit(failed === 0 ? 0 : 1);
