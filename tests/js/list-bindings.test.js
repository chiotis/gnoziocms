/*
 * List bindings in the block editor of a template built with blocks: public/assets/js/admin-blocks.js (with admin-panel.js) as shipped,
 * in jsdom, with the lists of a content type the code has never heard of.
 *
 *   1. the chain button   a block's list has one; the Fact sheet's own list (it names fields of the entry) and the editor of an entry do not
 *   2. the picker         offers the lists of the type, not single fields; a new binding maps the columns of the same name that fit
 *   3. the mapping table  one line for each column of the block's rows; a column offers only the columns of the entry's rows that fit it,
 *                         "Nothing" and "The same value" (a choice also: one for the first row, one for the others)
 *   4. warnings           a column that is gone or another kind now, and a list that is gone, get a chip and are kept as stored
 *   5. unbinding          "Type the list here instead" takes the binding away
 *
 *   node tests/js/list-bindings.test.js        (after: npm ci --prefix tests/js)
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
  type: 'buttons', label: 'Buttons', description: 'A row of buttons', preview: '', category: 'Content',
  fields: [{
    key: 'actions', label: 'Buttons', type: 'repeater', item_label: 'Button', max: 12,
    fields: [{ key: 'label', label: 'Label', type: 'text' }, { key: 'url', label: 'Link', type: 'link' }, select('style', 'Style', 'primary', ['primary', 'secondary', 'text']), { key: 'new_tab', label: 'New tab', type: 'toggle' },
      { key: 'parts', label: 'Parts', type: 'repeater', item_label: 'Part', fields: [{ key: 'name', label: 'Name', type: 'text' }] }]
  }],
  common: COMMON, design_fields: []
}, {
  type: 'cards', label: 'Cards', description: 'Cards', preview: '', category: 'Content',
  fields: [{ key: 'items', label: 'Cards', type: 'repeater', item_label: 'Card', fields: [{ key: 'title', label: 'Title', type: 'text' }, { key: 'text', label: 'Text', type: 'textarea' }, { key: 'image', label: 'Image', type: 'image' }, { key: 'url', label: 'Link', type: 'link' }] }],
  common: COMMON, design_fields: []
}, {
  type: 'fact-sheet', label: 'Fact sheet', description: 'Fields', preview: '', category: 'Entry', scope: 'template',
  fields: [{ key: 'fields', label: 'Fields', type: 'repeater', item_label: 'Field', fields: [{ key: 'field', label: 'Field', type: 'select', source: 'entry_field', default: '', options: [['', '-']] }] }],
  common: COMMON, design_fields: []
}, {
  type: 'text', label: 'Text', description: 'Some text', preview: '', category: 'Content',
  fields: [{ key: 'heading', label: 'Heading', type: 'text' }],
  common: COMMON, design_fields: []
}];

// The lists of a content type that exists only as a file of the site.
const src = (path, label, kind, group) => ({ path, key: path.replace('custom_fields.', ''), label, kind, group: group || 'Courses' });
const list = (path, label, columns) => Object.assign(src(path, label, 'list'), { columns });
const SOURCES = [
  src('title', 'Title', 'text', 'Entry'), src('custom_fields.headline', 'Headline', 'text'),
  list('custom_fields.resources', 'Resources', [
    { key: 'label', label: 'Name', kind: 'text' }, { key: 'url', label: 'Target', kind: 'url' }, { key: 'note', label: 'Note', kind: 'textarea' }, { key: 'size', label: 'Size', kind: 'number' }
  ]),
  list('referenced_by.books.author', 'Books · Author', [
    { key: 'title', label: 'Title', kind: 'text' }, { key: 'excerpt', label: 'Excerpt', kind: 'textarea' }, { key: 'main_image', label: 'Main image', kind: 'image' }, { key: 'url', label: 'Address of its page', kind: 'link' }
  ]),
  list('custom_fields.faq', 'Questions', [{ key: 'question', label: 'Question', kind: 'text' }, { key: 'answer', label: 'Answer', kind: 'markdown' }])
];
const ACCEPTS = { text: ['text', 'select', 'number', 'decimal', 'date', 'terms'], textarea: ['textarea', 'markdown'], markdown: ['textarea', 'markdown'], image: ['image'], link: ['link', 'url'], url: ['link', 'url'] };

function builder(blocks, options) {
  options = options || {};
  const template = options.context !== '';
  const data = { definitions: DEFS, container: null, blocks, presets: [] };
  if (template) {
    data.context = 'template';
    data.bindings = { type: 'education', sources: SOURCES, accepts: ACCEPTS };
  }
  const html = '<form><input type="hidden" data-block-editor-output><input type="hidden" data-block-editor-flag><div data-block-editor></div>'
    + '<script type="application/json" id="block-editor-data">' + JSON.stringify(data) + '</script></form>';
  const dom = new JSDOM('<!doctype html><body>' + html + '</body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/admin/theme' });
  const { window } = dom;
  const doc = window.document;
  window.Element.prototype.scrollIntoView = function () {};
  window.matchMedia = () => ({ matches: false, addEventListener() {} });
  const rect = window.Element.prototype.getBoundingClientRect;
  window.Element.prototype.getBoundingClientRect = function () {
    if (this.classList && this.classList.contains('sp-host')) return { left: 0, top: 0, right: 1000, bottom: 800, width: 1000, height: 800 };
    return rect.call(this);
  };
  window.eval(js('admin-sortable.js'));
  window.eval(js('admin-panel.js'));
  window.eval(js('admin-blocks.js'));
  const read = () => JSON.parse(doc.querySelector('[data-block-editor-output]').value);
  const panel = () => doc.querySelector('#bk-settings');
  const open = (n) => doc.querySelectorAll('[data-block-card]')[n].querySelector(':scope > .bk-head .bk-toggle').click();
  const idOf = () => panel().querySelector('[id^="blk-"]').id.split('-')[1];
  const chain = (key) => doc.querySelector('[aria-controls="blk-' + idOf() + '-' + key + '-sources"]');
  const offered = (key) => Array.prototype.map.call(panel().querySelectorAll('#blk-' + idOf() + '-' + key + '-sources .bk-source:not(.is-clear) code'), (n) => n.textContent);
  const choose = (key, source) => Array.prototype.find.call(panel().querySelectorAll('#blk-' + idOf() + '-' + key + '-sources .bk-source'), (n) => (n.querySelector('code') || {}).textContent === source).click();
  const table = () => panel().querySelector('[data-list-map]');
  const line = (col) => panel().querySelector('[data-map-col="' + col + '"]');
  const choices = (col) => Array.prototype.map.call(line(col).querySelector('select').options, (o) => o.value);
  const set = (select, value) => { select.value = value; select.dispatchEvent(new window.Event('change', { bubbles: true })); };
  return { window, doc, read, panel, open, chain, offered, choose, idOf, table, line, choices, set };
}


const canon = (v) => (v && typeof v === 'object' ? (Array.isArray(v) ? v.map(canon) : Object.keys(v).sort().reduce((o, k) => { o[k] = canon(v[k]); return o; }, {})) : v);
const same = (a, b) => JSON.stringify(canon(a)) === JSON.stringify(canon(b));

// ---- 1. the chain button
let b = builder([{ type: 'buttons' }]);
b.open(0);
check('a block\'s list has a chain button that says what it does', !!b.chain('actions') && /Show a list of the entry in Buttons/.test(b.chain('actions').getAttribute('aria-label')) && b.chain('actions').getAttribute('aria-expanded') === 'false');
const fact = builder([{ type: 'fact-sheet' }]);
fact.open(0);
check('the Fact sheet\'s own list, which names fields of the entry, has none', fact.panel().querySelector('.bk-bind') === null);
const entry = builder([{ type: 'buttons' }], { context: '' });
entry.open(0);
check('the editor of an entry has none', entry.panel().querySelector('.bk-bind') === null);

// ---- 2. the picker
b.chain('actions').click();
check('it offers the lists of the type, not its single fields', same(b.offered('actions'), ['resources', 'referenced_by.books.author', 'faq']), JSON.stringify(b.offered('actions')));
b.choose('actions', 'resources');
check('choosing a list stores a binding, with the columns of the same name that fit already mapped (the link takes the address, the choice nothing)', same(b.read()[0].actions, { bind: 'custom_fields.resources', map: { label: 'label', url: 'url' } }), JSON.stringify(b.read()));
const shown = b.panel().querySelector('.bk-bound');
check('the list shows what it is bound to, and the button to add rows is not shown', !!shown && /Resources/.test(shown.textContent) && !shown.classList.contains('is-warn') && b.panel().querySelector('.bk-rep-head .bk-btn').hidden === true, shown && shown.textContent);

// ---- 3. the mapping table
check('there is a line for each column of the block\'s rows, except a list inside a row', same(Array.prototype.map.call(b.table().querySelectorAll('[data-map-col]'), (n) => n.getAttribute('data-map-col')), ['label', 'url', 'style', 'new_tab']));
check('a text column offers the text and number columns of the entry\'s rows, nothing, and the same value', same(b.choices('label'), ['', 'map:label', 'map:size', '@fixed']), JSON.stringify(b.choices('label')));
check('a link column offers only the link and address columns', same(b.choices('url'), ['', 'map:url', '@fixed']), JSON.stringify(b.choices('url')));
check('a choice has no column to take, a switch not even a fixed value', same(b.choices('style'), ['', '@fixed']) && same(b.choices('new_tab'), ['']), JSON.stringify([b.choices('style'), b.choices('new_tab')]));
check('the columns that are mapped show it', b.line('label').querySelector('select').value === 'map:label' && b.line('url').querySelector('select').value === 'map:url' && b.line('style').querySelector('select').value === '');
b.set(b.line('label').querySelector('select'), 'map:size');
check('mapping another column changes the map and keeps the rest', same(b.read()[0].actions, { bind: 'custom_fields.resources', map: { label: 'size', url: 'url' } }), JSON.stringify(b.read()[0].actions));
b.set(b.line('url').querySelector('select'), '');
check('"Nothing" takes the column out of the map', same(b.read()[0].actions, { bind: 'custom_fields.resources', map: { label: 'size' } }), JSON.stringify(b.read()[0].actions));
b.set(b.line('style').querySelector('select'), '@fixed');
check('"The same value" for a choice stores its default and offers the first row and the others', same(b.read()[0].actions.fixed, { style: 'primary' }) && b.line('style').querySelectorAll('.bk-map-fixed select').length === 2, JSON.stringify(b.read()[0].actions));
const [firstRow, otherRows] = b.line('style').querySelectorAll('.bk-map-fixed select');
b.set(otherRows, 'secondary');
check('another value for the other rows is stored as first and rest', same(b.read()[0].actions.fixed, { style: { first: 'primary', rest: 'secondary' } }), JSON.stringify(b.read()[0].actions));
b.set(b.line('style').querySelectorAll('.bk-map-fixed select')[0], 'text');
check('the first row can change on its own', same(b.read()[0].actions.fixed, { style: { first: 'text', rest: 'secondary' } }), JSON.stringify(b.read()[0].actions));
b.set(b.line('label').querySelector('select'), '@fixed');
const typed = b.line('label').querySelector('.bk-map-fixed input[type=text]');
typed.value = 'Open';
typed.dispatchEvent(new b.window.Event('change', { bubbles: true }));
check('a text column can have one text for every row, and it leaves the map', same(b.read()[0].actions.fixed.label, 'Open') && (b.read()[0].actions.map || {}).label === undefined, JSON.stringify(b.read()[0].actions));
b.set(b.line('style').querySelector('select'), '');
check('"Nothing" takes a fixed value away too', b.read()[0].actions.fixed.style === undefined, JSON.stringify(b.read()[0].actions));
check('a bound block offers "hide when empty"', /Hide this block when its bound field is empty/.test(b.panel().textContent));

// ---- 4. warnings
b = builder([{ type: 'buttons', actions: { bind: 'custom_fields.resources', map: { label: 'removed_column', url: 'size' } } }]);
const chip = b.doc.querySelector('[data-bound-problem]');
check('a block mapped to a column that is gone has a warning chip, naming it', !!chip && /missing column: custom_fields.resources.removed_column/.test(chip.textContent) && chip.classList.contains('is-warn'), chip && chip.textContent);
b.open(0);
const warn = Array.prototype.map.call(b.panel().querySelectorAll('[data-map-problem]'), (n) => n.closest('[data-map-col]').getAttribute('data-map-col'));
check('in the panel the lines of the column that is gone and the one that no longer fits say so', same(warn, ['label', 'url']), JSON.stringify(warn));
check('a column that is gone stays visible, as what it is, and is kept as stored', /Missing: removed_column/.test(b.line('label').querySelector('select').selectedOptions[0].textContent) && b.read()[0].actions.map.label === 'removed_column', JSON.stringify(b.read()[0].actions));
b.set(b.line('label').querySelector('select'), 'map:label');
check('choosing a column that exists mends it', b.read()[0].actions.map.label === 'label' && same(Array.prototype.map.call(b.panel().querySelectorAll('[data-map-problem]'), (n) => n.closest('[data-map-col]').getAttribute('data-map-col')), ['url']));

b = builder([{ type: 'buttons', actions: { bind: 'custom_fields.gone_list', map: { label: 'label' } } }, { type: 'buttons', actions: { bind: 'custom_fields.headline' } }]);
const cards = b.doc.querySelectorAll('[data-block-card]');
check('a list that is gone, or that is a single field now, has a warning chip', /Bound to a missing field: custom_fields.gone_list/.test(cards[0].querySelector('[data-bound-problem]').textContent) && /no longer fits/.test(cards[1].querySelector('[data-bound-problem]').title), cards[0].textContent);
b.open(0);
check('the panel says so, shows no mapping, and keeps the binding as it is', /missing field: custom_fields.gone_list/.test(b.panel().querySelector('.bk-bound.is-warn').textContent) && b.table() === null && same(b.read()[0].actions, { bind: 'custom_fields.gone_list', map: { label: 'label' } }), JSON.stringify(b.read()[0].actions));

// ---- 5. unbinding
b = builder([{ type: 'buttons', actions: { bind: 'custom_fields.resources', map: { label: 'label' } } }]);
b.open(0);
b.chain('actions').click();
Array.prototype.find.call(b.panel().querySelectorAll('.bk-source'), (n) => n.classList.contains('is-clear')).click();
check('"Type the list here instead" takes the binding away and brings the button to add rows back', b.read()[0].actions === undefined && b.panel().querySelector('.bk-bound') === null && b.table() === null && b.panel().querySelector('.bk-rep-head .bk-btn').hidden === false, JSON.stringify(b.read()));

// ---- entries as a list: a card has an excerpt and a main image, which a block calls text and image
const cardsBuilder = builder([{ type: 'cards' }]);
cardsBuilder.open(0);
cardsBuilder.chain('items').click();
cardsBuilder.choose('items', 'referenced_by.books.author');
check('a list of entries maps its card to the block: title and link by name, the excerpt to the text, the main image to the image', same(cardsBuilder.read()[0].items, { bind: 'referenced_by.books.author', map: { title: 'title', text: 'excerpt', image: 'main_image', url: 'url' } }), JSON.stringify(cardsBuilder.read()));

console.log(failed ? 'FAILED: ' + failed : 'ALL PASSED');
process.exit(failed ? 1 : 0);
