/*
 * The menu editor's item settings, run as shipped (public/assets/js/admin-menus.js) in jsdom with the parts of the page it
 * needs:
 *
 *   1. the key helper      the nav.<menu>.<item> name suggested for an item: under its parent's key, under the menu's
 *                          key when the parent has none, and a number when another item has that key
 *   2. languages           the languages an item is shown in: unticking one, the last one kept, all ticked = no limit
 *   3. icon and badges     the icon chosen from the theme's list, a badge kept for its language
 *   4. link relations      rel values in the order the menu offers them, noopener kept as the file has it
 *   5. the list            the header says where an item is limited to, the preview leaves it out in other languages, undo
 *   6. roles               the roles an item is shown to: ticked in the site's order, nothing ticked = no limit, the header
 *                          names them, the preview (a visitor who is not signed in) leaves the item out, undo
 *
 *   node tests/js/menus-editor.test.js        (after: npm ci --prefix tests/js)
 */
'use strict';

const fs = require('fs');
const path = require('path');

let JSDOM;
let VirtualConsole;
try {
  ({ JSDOM, VirtualConsole } = require('jsdom'));
} catch (error) {
  console.log('FAIL jsdom is not installed: run  npm ci --prefix tests/js');
  process.exit(1);
}

let failed = 0;
function check(label, ok, detail) {
  if (!ok) { failed++; }
  console.log((ok ? 'ok   ' : 'FAIL ') + label + (ok ? '' : '  ' + String(detail === undefined ? '' : detail).replace(/\s+/g, ' ').slice(0, 300)));
}

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'admin-menus.js'), 'utf8');

/** One row as the server sends it (ids follow the order: 1 home, 2 services, 3 design, 4 planning, 5 contact). */
function row(depth, url, extra) {
  return Object.assign({
    depth, label_key: '', labels: { el: '', en: '' }, hints: { el: '', en: '' }, url, class: '', target: '', hidden: false,
    languages: [], icon: '', badges: { el: '', en: '' }, rel: [], open: false,
  }, extra);
}

const data = {
  key: 'main',
  default_language: 'el',
  languages: [{ code: 'el', name: 'Ελληνικά' }, { code: 'en', name: 'English' }],
  items: [
    row(1, '', { labels: { el: 'Αρχική', en: '' }, open: true }),
    row(1, 'services', { label_key: 'nav.main.services', labels: { el: 'Υπηρεσίες', en: 'Services' }, open: true }),
    row(2, 'services/design', { label_key: 'nav.main.services.design_build', labels: { el: 'Σχεδιασμός', en: '' }, open: true }),
    row(2, 'services/planning', { label_key: 'nav.main.contact', labels: { el: 'Μελέτη', en: '' }, languages: ['el'], open: true }),
    row(1, 'contact', { labels: { el: 'Επικοινωνία', en: 'Contact' }, languages: ['en'], open: true, icon: 'bolt', badges: { el: 'Νέο', en: '' }, rel: ['nofollow', 'noopener'], target: '_blank' }),
  ],
  groups: [],
  locations: [],
  can_place: false,
  icons: ['bolt', 'chart'],
  roles: [{ key: 'editor', name: 'Editor' }, { key: 'admin', name: 'Admin' }],
  rel_values: ['nofollow', 'sponsored', 'ugc', 'noopener', 'noreferrer'],
  max_items: 300,
};

const markup = `<div data-menu-editor>
<script type="application/json" id="menu-editor-data">${JSON.stringify(data).replace(/</g, '\\u003c')}</script>
<form data-menu-form>
  <input type="hidden" name="menu_json" value="" data-menu-json>
  <input type="text" name="menu_title" value="Κύριο" data-menu-title>
  <input type="search" data-source-search>
  <div data-source-groups></div>
  <div data-source-selected hidden><span data-source-selected-count></span><button type="button" data-source-clear></button><button type="button" data-source-add-selected></button></div>
  <input type="text" data-custom-url><input type="text" data-custom-label><button type="button" data-custom-add></button>
  <span data-menu-count></span>
  <button type="button" data-undo disabled></button><button type="button" data-redo disabled></button><button type="button" data-toggle-all></button>
  <div data-lang-bar><div data-lang-switch></div><div data-lang-banner></div></div>
  <div data-menu-list-wrap>
    <ul data-menu-list></ul><div data-drop-line hidden></div><div data-menu-empty hidden></div><p data-menu-note hidden></p>
  </div>
  <div data-menu-preview></div>
  <div data-menu-locations></div>
  <p data-menu-live></p>
  <button type="submit">Save</button>
</form>
</div>`;

// jsdom has no layout: scrolling a card into view is reported as not implemented, which is not a failure here.
const virtualConsole = new VirtualConsole();
virtualConsole.on('jsdomError', (error) => { if (!/scrollTo/.test(String(error.message))) console.log('jsdom: ' + error.message); });
const dom = new JSDOM('<!doctype html><body>' + markup + '</body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/', virtualConsole });
const { window } = dom;
const { document } = window;
window.eval(source);

const root = document.querySelector('[data-menu-editor]');
const body = (id) => root.querySelector('#menu-body-' + id);
const keyInput = (id) => body(id).querySelector('input[aria-label="Theme text key"]');
const labelled = (id, text) => [...body(id).querySelectorAll('label')].find((l) => l.textContent.trim() === text).querySelector('input');
const relBox = (id, value) => [...body(id).querySelectorAll('label')].find((l) => l.textContent.includes('rel="' + value + '"')).querySelector('input');
const suggest = (id) => {
  [...body(id).querySelectorAll('button')].find((b) => b.textContent === 'Suggest').click();
  return keyInput(id).value;
};
/** The menu as the form would send it: the items of the menu_json field after a submit. */
const saved = () => {
  root.querySelector('[data-menu-form]').dispatchEvent(new window.Event('submit', { cancelable: true }));
  return JSON.parse(root.querySelector('[data-menu-json]').value);
};

// ---- 1. the key helper
// (first: suggesting for an item sets its key, so the taken name is checked before the others are suggested)
check('a name another item has takes a number', suggest(5) === 'nav.main.contact_2', keyInput(5).value);
check('an item under a parent with a key is named under that key', suggest(3) === 'nav.main.services.design', keyInput(3).value);
check('so is the next one under it', suggest(4) === 'nav.main.services.planning', keyInput(4).value);
check('the home page is named after its address', suggest(1) === 'nav.main.home', keyInput(1).value);

// ---- 2. languages
const english = labelled(2, 'English');
english.click();
check('unticking English limits the item to Greek', saved()[1].languages.join() === 'el', saved()[1].languages);
labelled(2, 'Ελληνικά').click();
check('the last language cannot be unticked', labelled(2, 'Ελληνικά').checked === true && saved()[1].languages.join() === 'el');
english.click();
check('ticking every language stores no limit', saved()[1].languages.length === 0, saved()[1].languages);

// ---- 3. icon and badges
const select = body(5).querySelector('select[data-icon]');
check('the icon list is the theme\'s, with no icon first', [...select.options].map((o) => o.value).join('|') === '|bolt|chart', [...select.options].map((o) => o.value).join('|'));
check('an item shows its own icon', select.value === 'bolt', select.value);
select.value = 'chart';
select.dispatchEvent(new window.Event('change'));
check('a chosen icon is saved', saved()[4].icon === 'chart', saved()[4].icon);
const badgeEn = body(2).querySelector('[data-badge="en"]');
badgeEn.value = 'New';
badgeEn.dispatchEvent(new window.Event('input'));
check('a badge is saved for its own language', saved()[1].badges.en === 'New' && saved()[1].badges.el === '', saved()[1].badges);

// ---- 4. link relations
relBox(5, 'nofollow').click();
check('an unticked relation is removed and noopener is kept', saved()[4].rel.join() === 'noopener', saved()[4].rel);
relBox(5, 'sponsored').click();
check('a relation is added in the order the menu offers them', saved()[4].rel.join() === 'sponsored,noopener', saved()[4].rel);

// ---- 5. the list
const planning = root.querySelector('[data-id="4"]');
check('an item limited to one language says so in its header', planning.textContent.includes('EL'), planning.textContent);
check('no missing-label warning for a language the item is not shown in', !planning.textContent.includes('EN'), planning.textContent);
const preview = () => root.querySelector('[data-menu-preview]').textContent;
check('the preview leaves out an item that is not shown in the language being previewed', !preview().includes('Επικοινωνία') && preview().includes('Υπηρεσίες'), preview());
root.querySelector('[data-undo]').click();
check('undo brings back the relations of an item', saved()[4].rel.join() === 'noopener', saved()[4].rel);

// ---- 6. roles
check('nothing is ticked for an item with no role limit', labelled(1, 'Editor').checked === false && labelled(1, 'Admin').checked === false);
labelled(1, 'Editor').click();
check('ticking a role limits the item to it', saved()[0].roles.join() === 'editor', saved()[0].roles);
labelled(1, 'Admin').click();
check('a second role is added in the order the site has them', saved()[0].roles.join() === 'editor,admin', saved()[0].roles);
labelled(1, 'Editor').click();
labelled(1, 'Admin').click();
check('unticking the last role removes the limit', saved()[0].roles.length === 0, saved()[0].roles);
labelled(4, 'Admin').click();
check('an item limited to a role says so in its header', root.querySelector('[data-id="4"]').textContent.includes('Admin'), root.querySelector('[data-id="4"]').textContent);
check('the preview leaves it out: a visitor who is not signed in does not see it', !preview().includes('Μελέτη') && preview().includes('Υπηρεσίες'), preview());
root.querySelector('[data-undo]').click();
check('undo takes the role off again', saved()[3].roles.length === 0, saved()[3].roles);

console.log(failed === 0 ? '\nALL PASSED' : `\n${failed} FAILED`);
process.exit(failed === 0 ? 0 : 1);
