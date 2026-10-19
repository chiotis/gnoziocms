/*
 * The settings panel of the block builder: public/assets/js/admin-panel.js (the panel) with admin-blocks.js (the list that fills it),
 * both run as shipped in jsdom. jsdom has no layout, so the panel's place is set by giving the mount a width (docked) or none (over
 * the list), and matchMedia says whether the screen is a phone (the sheet).
 *
 *   1. the list           a compact row per block; a click opens its settings in the panel, the row is marked
 *   2. the panel          title, tabs (arrow keys), Previous and Next, a block of a column says where it is
 *   3. what is kept       opening and closing writes nothing; a field typed in the panel is written and the row's summary follows
 *   4. the list changes   a move keeps the panel on its block, a removed block closes it, a new block opens in it
 *   5. focus and Escape   docked: Escape gives the focus back to the row; over the list and on a phone it closes; the sheet keeps the
 *                         focus inside, and is a modal dialog
 *   6. the API            GnozioBlocks.open(path) for the live preview
 *   7. the width          dragged with the keyboard, within its limits, remembered
 *
 *   node tests/js/block-panel.test.js        (after: npm ci --prefix tests/js)
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

const select = (key, label, def, options) => ({ key, label, type: 'select', default: def, options: options.map((o) => [o, o]) });
const COMMON = [select('variant', 'Layout', 'default', ['default']), select('tone', 'Tone', 'default', ['default']), select('width', 'Width', '100', ['100', '50']), { key: 'anchor', label: 'Anchor', type: 'text' }, { key: 'hidden', label: 'Hide', type: 'toggle' }];
const DEFS = [{
  type: 'text', label: 'Text', description: 'Some text', preview: '', category: 'Content',
  fields: [{ key: 'heading', label: 'Heading', type: 'text' }, { key: 'body', label: 'Body', type: 'markdown' }],
  common: COMMON, design_fields: []
}, {
  type: 'cta', label: 'Call to action', description: 'A button', preview: '', category: 'Convert',
  fields: [{ key: 'heading', label: 'Heading', type: 'text' }, { key: 'style', label: 'Style', type: 'select', default: 'a', options: [['a', 'A'], ['b', 'B']] }, { key: 'note', label: 'Note', type: 'text', when: { style: ['b'] } }],
  common: COMMON, design_fields: []
}];
const CONTAINER = {
  type: 'container', label: 'Container', description: 'A row', max_columns: 6,
  presets: [{ id: '50-50', label: '50 / 50', widths: ['1/2', '1/2'] }],
  settings: [select('direction', 'Direction', 'row', ['row', 'row-reverse']), select('gap', 'Column gap', 'm', ['none', 'm', 'l']), select('stack_phone', 'Phone', 'one', ['row', 'two', 'one']), select('stack_desktop', 'Desktop', 'row', ['row', 'two', 'one']), select('stack_tablet', 'Tablet', 'auto', ['auto', 'row', 'two', 'one']), select('justify', 'Justify', 'start', ['start', 'center']), select('align', 'Align', 'stretch', ['stretch']), select('align_content', 'Align content', 'stretch', ['stretch']), select('wrap', 'Wrap', 'wrap', ['wrap', 'nowrap']), select('row_gap', 'Row gap', 'm', ['none', 'm']), select('tone', 'Background', 'default', ['default', 'muted']), { key: 'image', label: 'Picture', type: 'image', default: '' }, select('overlay', 'Overlay', 'none', ['none', 'dark']), select('inner', 'Inner width', 'container', ['container', 'narrow']), select('spacing', 'Spacing', 'default', ['default']), select('min_height', 'Min height', 'none', ['none']), { key: 'anchor', label: 'Anchor', type: 'text', default: '' }, { key: 'class', label: 'Class', type: 'text', default: '' }, { key: 'hidden', label: 'Hide', type: 'toggle', default: false }, { key: 'reverse_phone', label: 'Reverse', type: 'toggle', default: false }],
  columns: [{ key: 'width', label: 'Width', type: 'text', default: 'fill' }, { key: 'min_width', label: 'Min', type: 'text', default: '' }, { key: 'max_width', label: 'Max', type: 'text', default: '' }, { key: 'grow', label: 'Grow', type: 'number', default: '' }, { key: 'shrink', label: 'Shrink', type: 'number', default: '' }, { key: 'basis', label: 'Basis', type: 'text', default: '' }, select('align_self', 'Align self', 'auto', ['auto', 'start']), select('valign', 'Content', 'top', ['top', 'center']), { key: 'order', label: 'Order', type: 'number', default: 0 }, select('padding', 'Padding', 'none', ['none', 'm']), select('tone', 'Background', 'default', ['default', 'muted']), select('radius', 'Corners', 'none', ['none', 'm'])]
};
const T = (heading, extra) => Object.assign({ type: 'text', heading }, extra || {});
const container = (columns) => ({ type: 'container', settings: {}, columns: columns.map((blocks) => ({ settings: {}, blocks })) });

/** A builder in jsdom. `width` is the width of the mount (880 and more docks the panel); `phone` makes the screen a phone. */
function builder(blocks, options) {
  options = options || {};
  const html = '<form><input type="hidden" data-block-editor-output><input type="hidden" data-block-editor-flag><div data-block-editor></div>'
    + '<script type="application/json" id="block-editor-data">' + JSON.stringify({ definitions: DEFS, container: CONTAINER, blocks, presets: [] }) + '</script></form>';
  const dom = new JSDOM('<!doctype html><body>' + html + '</body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/admin/edit' });
  const { window } = dom;
  const doc = window.document;
  window.Element.prototype.scrollIntoView = function () {};
  window.matchMedia = () => ({ matches: !!options.phone, addEventListener() {} });
  const width = options.width || 0;
  const rect = window.Element.prototype.getBoundingClientRect;
  window.Element.prototype.getBoundingClientRect = function () {
    if (this.classList && this.classList.contains('sp-host')) return { left: 0, top: 0, right: width, bottom: 800, width, height: 800 };
    return rect.call(this);
  };
  if (options.storage) Object.keys(options.storage).forEach((key) => window.localStorage.setItem(key, options.storage[key]));
  window.eval(js('admin-sortable.js'));
  window.eval(js('admin-panel.js'));
  window.eval(js('admin-blocks.js'));
  const read = () => JSON.parse(doc.querySelector('[data-block-editor-output]').value);
  const panel = () => doc.querySelector('#bk-settings');
  const rows = () => doc.querySelectorAll('[data-block-card]');
  const toggleOf = (n) => rows()[n].querySelector(':scope > .bk-head .bk-toggle');
  const title = () => panel().querySelector('.sp-title').textContent;
  const key = (node, name, extra) => {
    const event = new window.KeyboardEvent('keydown', Object.assign({ key: name, bubbles: true, cancelable: true }, extra || {}));
    node.dispatchEvent(event);
    return event;
  };
  return { window, doc, read, panel, rows, toggleOf, title, key };
}

// ---- 1. the list
let b = builder([T('One', { body: 'Hello there' }), T('Two'), { type: 'cta', heading: 'Go' }], { width: 1000 });
check('each block is one compact row: no fields in the list', b.rows().length === 3 && b.doc.querySelector('.bk-list input:not(.bk-select), .bk-list textarea') === null);
check('the rows have no Expand all button any more', !Array.prototype.some.call(b.doc.querySelectorAll('button'), (n) => /Expand all|Collapse all/.test(n.textContent)));
check('docked (a wide editor), the panel is there with a hint while nothing is chosen', !b.panel().hidden && b.doc.querySelector('.sp-host').classList.contains('is-docked') && /Choose a block/.test(b.panel().textContent) && b.panel().getAttribute('aria-label') === 'Block settings');
check('each row says which panel it opens', b.toggleOf(0).getAttribute('aria-controls') === 'bk-settings' && b.toggleOf(0).getAttribute('aria-expanded') === 'false');
b.toggleOf(1).click();
check('a click on a row opens its settings in the panel', b.title() === '2. Text' && b.panel().getAttribute('role') === 'region' && b.panel().getAttribute('aria-labelledby') === 'bk-settings-title', b.title());
check('the row is marked as the one open', b.rows()[1].classList.contains('is-selected') && b.toggleOf(1).getAttribute('aria-expanded') === 'true' && b.toggleOf(0).getAttribute('aria-expanded') === 'false');
check('the focus goes to the panel\'s title', b.doc.activeElement === b.panel().querySelector('.sp-title'));
check('it is said which block it is', /Settings of block Text, 2 of 3/.test(b.panel().querySelector('[aria-live]').textContent));

// ---- 2. the panel
const tabs = () => Array.prototype.map.call(b.panel().querySelectorAll('[role=tab]'), (n) => n.textContent + (n.getAttribute('aria-selected') === 'true' ? '*' : '')).join('|');
check('Content and Design tabs, Content first', tabs() === 'Content*|Design', tabs());
check('the Content tab has the block\'s fields', !!b.panel().querySelector('#blk-2-heading') && b.panel().querySelector('#blk-2-heading').value === 'Two');
b.key(b.panel().querySelector('[role=tab]'), 'ArrowRight');
check('the right arrow on a tab shows Design and moves the focus there', tabs() === 'Content|Design*' && b.doc.activeElement === b.panel().querySelector('[data-tab=design]') && !!b.panel().querySelector('.bk-widths'), tabs());
check('the body is the tab panel, named by its tab', b.panel().querySelector('[role=tabpanel]').getAttribute('aria-labelledby') === 'bk-settings-tab-design');
b.panel().querySelector('[data-panel-next]').click();
check('Next opens the next block, keeping the tab', b.title() === '3. Call to action' && tabs() === 'Content|Design*', b.title());
check('at the end Next cannot be pressed again, so the title takes the focus', b.doc.activeElement === b.panel().querySelector('.sp-title'), b.doc.activeElement && b.doc.activeElement.outerHTML.slice(0, 80));
check('the last block has no Next', b.panel().querySelector('[data-panel-next]').disabled && b.panel().querySelector('[data-panel-prev]').getAttribute('aria-label') === 'Previous: Text');
b.panel().querySelector('[data-panel-prev]').focus();
b.panel().querySelector('[data-panel-prev]').click();
check('Previous keeps the focus on itself while it can go on', b.doc.activeElement === b.panel().querySelector('[data-panel-prev]') && b.title() === '2. Text');
b.panel().querySelector('[data-panel-prev]').click();
check('Previous goes back, the first has no Previous', b.title() === '1. Text' && b.panel().querySelector('[data-panel-prev]').disabled);

b = builder([T('Top'), container([[T('A')], [{ type: 'cta', heading: 'B' }]])], { width: 1000 });
b.doc.querySelectorAll('.bk-col')[1].querySelector('.bk-toggle').click();
check('a block of a column says where it is', b.title() === '1. Call to action' && b.panel().querySelector('.sp-crumbs').textContent === 'Container 2 › Column 2', b.panel().querySelector('.sp-crumbs').textContent);
b.panel().querySelector('[data-panel-prev]').click();
check('Previous and Next go through the blocks inside containers too', b.title() === '1. Text' && b.panel().querySelector('.sp-crumbs').textContent === 'Container 2 › Column 1');

// ---- 3. what is kept
const stored = [T('One', { body: 'Hello', tone: 'default' }), container([[T('A')], [{ type: 'cta', heading: 'B', style: 'b', note: 'n' }]]), T('Half', { width: '50' })];
b = builder(stored, { width: 1000 });
const before = JSON.stringify(b.read());
check('what is written is what was stored', before === JSON.stringify(stored), before);
for (let i = 0; i < b.rows().length; i++) {
  b.toggleOf(i).click();
  b.panel().querySelectorAll('[role=tab]').forEach((tab) => tab.click());
}
b.panel().querySelector('[data-panel-close]').click();
check('opening every block and every tab, then closing, changes nothing that is written', JSON.stringify(b.read()) === before, JSON.stringify(b.read()));
check('no editor-only state is written', JSON.stringify(b.read()).indexOf('_') === -1);
b.toggleOf(0).click();
b.panel().querySelector('[data-tab=content]').click();
const heading = b.panel().querySelector('#blk-1-heading');
let changes = 0;
b.doc.addEventListener('bk:change', () => { changes++; });
heading.value = 'One, changed';
heading.dispatchEvent(new b.window.Event('input', { bubbles: true }));
check('a field typed in the panel is written, and the preview is told', b.read()[0].heading === 'One, changed' && changes === 1);
check('the row\'s short text follows what is typed', b.rows()[0].querySelector('[data-block-summary]').textContent === 'One, changed');
check('typing does not draw the panel again: the field keeps the focus', b.doc.activeElement === heading || heading.isConnected);
const cta = Array.prototype.find.call(b.doc.querySelectorAll('.bk-col [data-block-card]'), (r) => /Call to action/.test(r.textContent));
cta.querySelector('.bk-toggle').click();
const note = () => b.panel().querySelector('#' + 'blk-' + cta.getAttribute('data-block-card') + '-note').closest('.bk-field');
check('a field with `when` shows while the other field fits', !note().hidden);
b.panel().querySelector('[data-value="a"]').click();
check('and hides when it no longer does', note().hidden && b.read()[1].columns[1].blocks[0].style === 'a');

// ---- 4. the list changes
b = builder([T('One'), T('Two'), T('Three')], { width: 1000 });
b.toggleOf(2).click();
const menuOf = (n, label) => { b.rows()[n].querySelector('[data-action="more"]').click(); return Array.prototype.find.call(b.rows()[n].querySelectorAll('.bk-menu-item'), (i) => i.textContent.indexOf(label) !== -1); };
menuOf(2, 'Move to top').click();
check('a move keeps the panel on its block, with its new number', b.title() === '1. Text' && b.panel().querySelector('#blk-3-heading').value === 'Three' && b.rows()[0].classList.contains('is-selected'), b.title());
menuOf(0, 'Wrap in container').click();
check('wrapped in a container, the panel says the block is in a column (and offers no Width)', b.panel().querySelector('.sp-crumbs').textContent === 'Container 1 › Column 1' && (b.panel().querySelector('[data-tab=design]').click(), b.panel().querySelector('.bk-widths') === null));
b.doc.querySelector('.bk-col [data-block-card] [data-action="remove"]').click();
check('removing the block that is open closes its settings', b.panel().querySelector('.sp-frame').hidden && !/Three/.test(b.panel().textContent) && b.doc.querySelector('.is-selected') === null);
b.doc.querySelector('.bk-note .bk-link').click();
check('Undo puts it back and its row has the focus', b.doc.activeElement && b.doc.activeElement.closest('[data-block-card]') !== null);
const add = Array.prototype.find.call(b.doc.querySelectorAll('.bk-bar button'), (n) => /Add block/.test(n.textContent));
add.click();
Array.prototype.find.call(b.doc.querySelectorAll('.bk-pchoose'), (n) => /Call to action/.test(n.textContent)).click();
check('a new block from the picker opens in the panel on its Content tab, with the focus there', /Call to action/.test(b.title()) && b.panel().querySelector('[data-tab=content]').getAttribute('aria-selected') === 'true' && b.doc.activeElement === b.panel().querySelector('.sp-title'), b.title());
b = builder([T('One'), T('Two')], { width: 1000 });
b.toggleOf(0).click();
b.rows()[0].querySelector('[data-action="duplicate"]').click();
check('a duplicate takes the panel while it is open, the focus stays on its row', b.doc.activeElement === b.toggleOf(1) && b.toggleOf(1).getAttribute('aria-expanded') === 'true' && b.title() === '2. Text' && JSON.stringify(b.read().map((x) => x.heading)) === '["One","One","Two"]');

// ---- 5. focus and Escape
b = builder([T('One'), T('Two')], { width: 1000 });
b.toggleOf(1).click();
let esc = b.key(b.panel().querySelector('#blk-2-heading'), 'Escape');
check('docked: Escape gives the focus back to the row and the panel stays', esc.defaultPrevented && b.doc.activeElement === b.toggleOf(1) && !b.panel().querySelector('.sp-frame').hidden);
b.panel().querySelector('[data-panel-close]').click();
check('docked: Close clears the panel and the focus goes back to the row', b.panel().querySelector('.sp-frame').hidden && !b.panel().hidden && b.doc.activeElement === b.toggleOf(1));

b = builder([T('One'), T('Two')], { width: 600 });
check('a narrow editor: the panel shows only while a block is open', b.doc.querySelector('.sp-host').classList.contains('is-overlay') && b.panel().hidden);
b.toggleOf(0).click();
check('over the list it is a region, not a modal', !b.panel().hidden && b.panel().getAttribute('role') === 'region' && !b.panel().hasAttribute('aria-modal'));
b.key(b.panel().querySelector('#blk-1-heading'), 'Escape');
check('over the list, Escape closes it and gives the focus back to the row', b.panel().hidden && b.doc.activeElement === b.toggleOf(0));

b = builder([T('One'), T('Two')], { phone: true, width: 375 });
b.toggleOf(0).click();
check('a phone: the whole screen, a modal dialog, the page does not scroll behind it', b.doc.querySelector('.sp-host').classList.contains('is-sheet') && b.panel().getAttribute('role') === 'dialog' && b.panel().getAttribute('aria-modal') === 'true' && b.doc.documentElement.classList.contains('sp-locked'));
check('with a Back button to the list', !b.panel().querySelector('[data-panel-back]').hidden);
const controls = b.panel().querySelectorAll('.sp-body input, .sp-body textarea, .sp-body button');
const last = controls[controls.length - 1];
last.focus();
b.key(last, 'Tab');
check('the focus goes round inside the sheet: Tab on the last control goes to the first (Back)', b.doc.activeElement === b.panel().querySelector('[data-panel-back]'), b.doc.activeElement && b.doc.activeElement.outerHTML.slice(0, 80));
b.key(b.doc.activeElement, 'Tab', { shiftKey: true });
check('and Shift+Tab on the first goes to the last', b.doc.activeElement === last);
b.panel().querySelector('[data-panel-back]').click();
check('Back closes the sheet, the page scrolls again, the focus is on the row', b.panel().hidden && !b.doc.documentElement.classList.contains('sp-locked') && b.doc.activeElement === b.toggleOf(0));

// ---- 6. the API
b = builder([T('Top'), container([[T('A')], [T('B'), { type: 'cta', heading: 'C' }]])], { width: 1000 });
check('GnozioBlocks.open(path) opens the block at that place', b.window.GnozioBlocks.open('1.1.1') === true && b.title() === '2. Call to action' && b.panel().querySelector('.sp-crumbs').textContent === 'Container 2 › Column 2');
check('a container by its place', b.window.GnozioBlocks.open('1') === true && b.title() === '2. Container');
check('a place with no block says so', b.window.GnozioBlocks.open('7') === false && b.window.GnozioBlocks.open('1.4.0') === false);
b.window.GnozioBlocks.close();
check('and close() closes', b.panel().querySelector('.sp-frame').hidden);

// ---- 7. the width
b = builder([T('One')], { width: 1000, storage: { 'gnozio.panel.blocks.width': '420' } });
const host = b.doc.querySelector('.sp-host');
check('the width is the remembered one', host.style.getPropertyValue('--sp-width') === '420px');
const grip = b.panel().querySelector('.sp-grip');
b.key(grip, 'ArrowLeft');
check('the left arrow on the edge widens it and remembers', host.style.getPropertyValue('--sp-width') === '436px' && b.window.localStorage.getItem('gnozio.panel.blocks.width') === '436');
for (let i = 0; i < 20; i++) b.key(grip, 'ArrowLeft', { shiftKey: true });
check('it stops at its widest', host.style.getPropertyValue('--sp-width') === '560px' && grip.getAttribute('aria-valuenow') === '560');
b.key(grip, 'End');
check('End makes it as narrow as it goes', host.style.getPropertyValue('--sp-width') === '340px');

console.log(failed ? 'FAILED: ' + failed : 'ALL PASSED');
process.exit(failed ? 1 : 0);
