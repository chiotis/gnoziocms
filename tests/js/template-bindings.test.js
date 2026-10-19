/*
 * Field bindings in the block editor of a template built with blocks: public/assets/js/admin-blocks.js (with admin-panel.js) as
 * shipped, in jsdom, with the fields of a content type the code has never heard of (the picker is built from data.bindings).
 *
 *   1. the chain button    a field a binding fits has one; a choice, a switch or a list does not; the entry editor has none
 *   2. the picker          offers only the fields that fit this kind of field (a text takes text, select, number and date; a Markdown
 *                          field takes text areas; a picture takes pictures; a link takes links and addresses)
 *   3. binding             stores {bind: path}, shows what it is bound to, hides the typed control; "type it here" takes it away
 *   4. hide when empty     on unless switched off, offered once a field is bound; only the switched-off state is written
 *   5. fields that went    a warning chip on the block and on the field: a field that is gone, one that is another kind now; a Fact
 *                          sheet's list shows a field of the type that is gone
 *
 *   node tests/js/template-bindings.test.js        (after: npm ci --prefix tests/js)
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
  fields: [{ key: 'heading', label: 'Heading', type: 'text' }, { key: 'body', label: 'Body', type: 'markdown' }, select('align', 'Alignment', 'left', ['left', 'center']), { key: 'wide', label: 'Wide', type: 'toggle' }],
  common: COMMON, design_fields: []
}, {
  type: 'image', label: 'Image', description: 'A picture', preview: '', category: 'Media',
  fields: [{ key: 'image', label: 'Image', type: 'image' }, { key: 'link', label: 'Link', type: 'link' }],
  common: COMMON, design_fields: []
}, {
  type: 'fact-sheet', label: 'Fact sheet', description: 'Fields', preview: '', category: 'Entry', scope: 'template',
  fields: [{ key: 'fields', label: 'Fields', type: 'repeater', item_label: 'Field', fields: [{ key: 'field', label: 'Field', type: 'select', source: 'entry_field', default: '', options: [['', '—'], ['custom_fields.hours', 'Hours (hours)']] }] }],
  common: COMMON, design_fields: []
}];

// The fields of a content type that exists only as a file of the site (tests/unit/entry-bindings.php has the same one).
const src = (path, label, kind, group) => ({ path, key: path.replace('custom_fields.', ''), label, kind, group: group || 'Courses' });
const SOURCES = [
  src('title', 'Title', 'text', 'Entry'), src('excerpt', 'Excerpt', 'textarea', 'Entry'), src('main_image', 'Main image', 'image', 'Entry'), src('date', 'Date', 'date', 'Entry'),
  src('custom_fields.headline', 'Headline', 'text'), src('custom_fields.level', 'Level', 'select'), src('custom_fields.hours', 'Hours', 'number'), src('custom_fields.starts', 'Starts', 'date'),
  src('custom_fields.summary', 'Summary', 'textarea'), src('custom_fields.syllabus', 'Syllabus', 'markdown'), src('custom_fields.cover', 'Cover', 'image'),
  src('custom_fields.apply', 'Apply', 'link'), src('custom_fields.website', 'Website', 'url')
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
  return { window, doc, read, panel, open, chain, offered, choose, idOf };
}

// ---- 1. the chain button
let b = builder([{ type: 'text', heading: 'Typed', body: 'Words' }]);
b.open(0);
check('a text and a Markdown field have a chain button', !!b.chain('heading') && !!b.chain('body'));
check('a choice and a switch have none', b.panel().querySelector('[aria-controls$="-align-sources"]') === null && b.panel().querySelector('[aria-controls$="-wide-sources"]') === null);
check('the button says what it does and is collapsed', /Show a field of the entry in Heading/.test(b.chain('heading').getAttribute('aria-label')) && b.chain('heading').getAttribute('aria-expanded') === 'false');
const entry = builder([{ type: 'text', heading: 'Typed' }], { context: '' });
entry.open(0);
check('the editor of an entry has no chain buttons', entry.panel().querySelector('.bk-bind') === null && !!entry.panel().querySelector('input[type=text]'));

// ---- 2. only the fields that fit
b.chain('heading').click();
check('the list opens', b.chain('heading').getAttribute('aria-expanded') === 'true' && !b.panel().querySelector('#blk-' + b.idOf() + '-heading-sources').hidden);
check('a text takes the title, the date, and the text, select, number and date fields', JSON.stringify(b.offered('heading')) === JSON.stringify(['title', 'date', 'headline', 'level', 'hours', 'starts']), JSON.stringify(b.offered('heading')));
b.chain('body').click();
check('a Markdown field takes the excerpt and the text areas', JSON.stringify(b.offered('body')) === JSON.stringify(['excerpt', 'summary', 'syllabus']), JSON.stringify(b.offered('body')));
b = builder([{ type: 'image' }]);
b.open(0);
b.chain('image').click();
b.chain('link').click();
check('a picture takes the main image and picture fields; a link takes links and addresses', JSON.stringify([b.offered('image'), b.offered('link')]) === JSON.stringify([['main_image', 'cover'], ['apply', 'website']]), JSON.stringify([b.offered('image'), b.offered('link')]));
const groups = Array.prototype.map.call(b.panel().querySelectorAll('#blk-' + b.idOf() + '-image-sources .bk-sources-group'), (n) => n.textContent);
check('the fields are listed by label and key, the entry\'s own first and the type\'s under its name', JSON.stringify(groups) === JSON.stringify(['Entry', 'Courses']) && /Cover/.test(b.panel().textContent), JSON.stringify(groups));

// ---- 3. binding
b = builder([{ type: 'text', heading: 'Typed' }]);
b.open(0);
b.chain('heading').click();
b.choose('heading', 'headline');
check('choosing a field stores a binding where the value would be', JSON.stringify(b.read()[0].heading) === JSON.stringify({ bind: 'custom_fields.headline' }), JSON.stringify(b.read()));
const bound = b.panel().querySelector('.bk-bound');
check('the field shows what it is bound to, and its typed control is not shown', !!bound && /Headline/.test(bound.textContent) && bound.querySelector('code').textContent === 'headline' && b.panel().querySelector('#blk-' + b.idOf() + '-heading').hidden, bound && bound.textContent);
check('no warning while the field is there', !bound.classList.contains('is-warn') && b.doc.querySelector('[data-bound-problem]') === null);
Array.prototype.find.call(b.panel().querySelectorAll('.bk-source'), (n) => n.classList.contains('is-clear')).click();
check('"Type it here instead" takes the binding away', b.read()[0].heading === undefined && b.panel().querySelector('.bk-bound') === null && b.panel().querySelector('#blk-' + b.idOf() + '-heading').hidden === false, JSON.stringify(b.read()));

// ---- 4. hide when empty
b = builder([{ type: 'text', heading: 'Typed' }]);
b.open(0);
check('a block with nothing bound does not offer it', !/Hide this block when its bound field is empty/.test(b.panel().textContent));
b = builder([{ type: 'text', heading: { bind: 'title' } }]);
b.open(0);
const hide = Array.prototype.find.call(b.panel().querySelectorAll('input[type=checkbox]'), (n) => /bound field is empty/.test(n.parentNode.textContent));
check('a bound block does, and it is on', !!hide && hide.checked === true && b.read()[0].bind_hide === undefined);
hide.checked = false;
hide.dispatchEvent(new b.window.Event('change', { bubbles: true }));
check('switched off, it is written', b.read()[0].bind_hide === false, JSON.stringify(b.read()));
hide.checked = true;
hide.dispatchEvent(new b.window.Event('change', { bubbles: true }));
check('and switched on again, it is not', b.read()[0].bind_hide === undefined);

// ---- 5. fields that went
b = builder([{ type: 'text', heading: { bind: 'custom_fields.removed' }, body: { bind: 'custom_fields.cover' } }, { type: 'text', heading: { bind: 'title' } }]);
const rows = b.doc.querySelectorAll('[data-block-card]');
const chip = rows[0].querySelector('[data-bound-problem]');
check('a block bound to a field that is gone has a warning chip, naming it', !!chip && /Bound to a missing field: custom_fields.removed/.test(chip.textContent) && chip.classList.contains('is-warn'), chip && chip.textContent);
check('a block whose fields are all there has none', rows[1].querySelector('[data-bound-problem]') === null);
b.open(0);
const warned = Array.prototype.map.call(b.panel().querySelectorAll('.bk-bound.is-warn'), (n) => n.textContent);
check('in the panel each field says what is wrong: gone, or no longer the right kind', warned.length === 2 && /missing field: custom_fields.removed/.test(warned[0]) && /no longer fits: custom_fields.cover/.test(warned[1]), warned.join(' | '));
check('a binding that is gone is kept as it is, so nothing is lost', JSON.stringify(b.read()[0].heading) === JSON.stringify({ bind: 'custom_fields.removed' }));
b.chain('heading').click();
b.choose('heading', 'title');
check('it can be replaced by a field that exists', JSON.stringify(b.read()[0].heading) === JSON.stringify({ bind: 'title' }) && b.doc.querySelector('[data-bound-problem]') !== null);

b = builder([{ type: 'fact-sheet', fields: [{ field: 'custom_fields.hours' }, { field: 'custom_fields.gone' }] }]);
const factChip = b.doc.querySelector('[data-bound-problem]');
check('a Fact sheet naming a field the type no longer has gets a warning chip too', !!factChip && /Uses a missing field: custom_fields.gone/.test(factChip.textContent), factChip && factChip.textContent);
b.open(0);
for (let i = 0; i < 3 && b.panel().querySelector('.bk-row-toggle[aria-expanded="false"]'); i++) b.panel().querySelector('.bk-row-toggle[aria-expanded="false"]').click();
const options = Array.prototype.map.call(b.panel().querySelectorAll('select option'), (n) => n.value + ':' + n.textContent + (n.selected ? '*' : ''));
check('and its list shows that field as missing, still selected', options.some((o) => /^custom_fields.gone:Missing field: custom_fields.gone\*$/.test(o)), options.join(' | '));

console.log(failed ? 'FAILED: ' + failed : 'ALL PASSED');
process.exit(failed ? 1 : 0);
