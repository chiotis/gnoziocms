/*
 * The picker of a relation field (public/assets/js/admin-relations.js), run as shipped in jsdom on the real markup of
 * admin/templates/partials/fields.twig (drawn by tests/js/render-relation.php): chips, the search box and its list, choosing, removing and
 * moving entries, a field that takes several and its limit, the keyboard, and a field inside a row of a list that is added and moved.
 *
 *   node tests/js/relations.test.js        (after: npm ci --prefix tests/js)
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

const entries = {
  aaaaaaaa00000001: { title: 'Ada Lovelace', type: 'authors', status: 'published', hidden: false, edit_url: '/admin/edit?type=authors&slug=ada&lang=el' },
  bbbbbbbb00000002: { title: 'Charles Babbage', type: 'authors', status: 'published', hidden: false, edit_url: '/admin/edit?type=authors&slug=charles&lang=el' },
  cccccccc00000003: { title: 'Cy Draft', type: 'authors', status: 'draft', hidden: true, edit_url: '/admin/edit?type=authors&slug=cy&lang=el' }
};
const markup = execFileSync('php', [path.join(__dirname, 'render-relation.php')], {
  input: JSON.stringify({
    definitions: {
      author: { type: 'relation', types: ['authors'], label: 'Author' },
      team: { type: 'relation', types: ['authors'], multiple: true, max: 3, label: 'Team' },
      editions: { type: 'repeater', item_label: 'Edition', fields: { year: { type: 'number' }, editor: { type: 'relation', types: ['authors'] } } }
    },
    name: 'details',
    values: { author: 'aaaaaaaa00000001', team: ['cccccccc00000003', 'bbbbbbbb00000002'], editions: [{ year: 1843, editor: 'bbbbbbbb00000002' }] },
    labels: entries
  })
}).toString();

const dom = new JSDOM('<!doctype html><body><form>' + markup + '</form></body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/admin/edit' });
const { window } = dom;
const doc = window.document;

// The search address: answers like the server (entries of the types asked for whose title has the text).
const asked = [];
window.fetch = (url) => {
  asked.push(url);
  const query = decodeURIComponent((url.match(/[?&]q=([^&]*)/) || [, ''])[1]).toLowerCase();
  const rows = Object.keys(entries).filter((id) => entries[id].title.toLowerCase().includes(query)).map((id) => Object.assign({ value: id }, entries[id]));
  return Promise.resolve({ ok: true, json: () => Promise.resolve(rows) });
};
window.eval(js('admin-relations.js'));
window.eval(js('admin.js'));
doc.dispatchEvent(new window.Event('DOMContentLoaded'));

const sent = () => Array.prototype.filter.call(doc.querySelector('form').elements, (c) => c.name && !c.disabled).map((c) => c.name + '=' + c.value);
const box = (name) => doc.querySelector('[data-relation] [data-rel-name][name="' + name + '"]').closest('[data-relation]');
const titles = (b) => Array.prototype.map.call(b.querySelectorAll('[data-rel-chip] .rel-title'), (n) => n.textContent);
const tick = () => new Promise((resolve) => setTimeout(resolve, 220));
const type = async (b, text) => {
  const search = b.querySelector('[data-rel-search]');
  search.focus();
  search.value = text;
  search.dispatchEvent(new window.Event('input', { bubbles: true }));
  await tick();
};
const options = (b) => Array.prototype.map.call(b.querySelectorAll('[data-rel-results] [role=option] .rel-title'), (n) => n.textContent);
const press = (b, key) => b.querySelector('[data-rel-search]').dispatchEvent(new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
const mousedown = (node) => node.dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
const click = (node) => node.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

(async () => {
  check('what the server drew is sent as it is: the ids in the chips, in their order, nothing for the name holder', JSON.stringify(sent()) === JSON.stringify([
    'details[author]=aaaaaaaa00000001', 'details[team][]=cccccccc00000003', 'details[team][]=bbbbbbbb00000002', 'details[editions]=', 'details[editions][0][year]=1843', 'details[editions][0][editor]=bbbbbbbb00000002'
  ]), sent());
  check('a draft is a chip marked with its state', box('details[team]').querySelector('.rel-chip.is-hidden .rel-state').textContent === 'draft');

  const author = box('details[author]');
  const team = box('details[team]');

  // The search box.
  await type(author, 'cha');
  check('typing asks the server for that text and the types of the field', asked.length > 0 && /[?&]q=cha&types=authors$/.test(asked[asked.length - 1]), asked);
  check('the answers are listed, the first one active', JSON.stringify(options(author)) === JSON.stringify(['Charles Babbage']) && author.querySelector('[role=option]').getAttribute('aria-selected') === 'true', options(author));
  mousedown(author.querySelector('[role=option]'));
  check('choosing replaces the one chip of a single field and sends its id', JSON.stringify(titles(author)) === JSON.stringify(['Charles Babbage']) && sent().includes('details[author]=bbbbbbbb00000002') && !sent().includes('details[author]=aaaaaaaa00000001'), sent());
  check('the list closes and the box is empty', author.querySelector('[data-rel-results]').hidden && author.querySelector('[data-rel-search]').value === '');
  click(author.querySelector('[data-rel-remove]'));
  check('removing the chip of a single field sends nothing for it', !sent().some((s) => s.startsWith('details[author]')) && titles(author).length === 0, sent());

  // A field that takes several.
  await type(team, '');
  check('with no text the first entries are listed, without the ones already chosen', JSON.stringify(options(team)) === JSON.stringify(['Ada Lovelace']), options(team));
  press(team, 'Enter');
  check('Enter chooses the active entry and does not send the form', JSON.stringify(titles(team)) === JSON.stringify(['Cy Draft', 'Charles Babbage', 'Ada Lovelace']) && sent().filter((s) => s.startsWith('details[team]')).join() === 'details[team][]=cccccccc00000003,details[team][]=bbbbbbbb00000002,details[team][]=aaaaaaaa00000001', sent());
  check('a field at its limit takes no more: the box is off', team.querySelector('[data-rel-search]').disabled);
  click(team.querySelectorAll('[data-rel-chip]')[2].querySelector('[data-rel-left]'));
  check('an entry moves earlier and the ids follow', sent().filter((s) => s.startsWith('details[team]')).join() === 'details[team][]=cccccccc00000003,details[team][]=aaaaaaaa00000001,details[team][]=bbbbbbbb00000002', sent());
  click(team.querySelectorAll('[data-rel-chip]')[0].querySelector('[data-rel-right]'));
  check('and later', titles(team).join() === 'Ada Lovelace,Cy Draft,Charles Babbage', titles(team));
  click(team.querySelectorAll('[data-rel-chip]')[1].querySelector('[data-rel-remove]'));
  check('removing one frees the box again', !team.querySelector('[data-rel-search]').disabled && titles(team).join() === 'Ada Lovelace,Charles Babbage');
  await type(team, 'cy');
  press(team, 'Escape');
  check('Escape puts the list away', team.querySelector('[data-rel-results]').hidden);
  await type(team, 'ada');
  check('an entry that is already chosen is not offered twice', options(team).length === 0 && /already chosen/.test(team.querySelector('[data-rel-results]').textContent));

  // A field inside a row of a list.
  const rows = () => Array.prototype.filter.call(doc.querySelector('[data-repeater-name="details[editions]"]').querySelector(':scope > [data-repeater-rows]').children, (n) => n.matches('[data-repeater-row]'));
  click(doc.querySelector('[data-repeater-name="details[editions]"] > [data-repeater-add]'));
  check('a new row has a picker with the name of its row, nothing chosen', rows().length === 2 && rows()[1].querySelector('[data-rel-name]').name === 'details[editions][1][editor]' && rows()[1].querySelectorAll('[data-rel-chip]').length === 0);
  const second = rows()[1].querySelector('[data-relation]');
  await type(second, 'ada');
  mousedown(second.querySelector('[role=option]'));
  check('an entry chosen in the new row is sent under the name of that row', sent().includes('details[editions][1][editor]=aaaaaaaa00000001'), sent());
  click(rows()[1].querySelector('[data-repeater-up]'));
  check('moving the row renames the chips with it', sent().includes('details[editions][0][editor]=aaaaaaaa00000001') && sent().includes('details[editions][1][editor]=bbbbbbbb00000002'), sent());
  await type(rows()[0].querySelector('[data-relation]'), 'cha');
  mousedown(rows()[0].querySelector('[role=option]'));
  check('and a chip added after the move takes the new name', sent().includes('details[editions][0][editor]=bbbbbbbb00000002') && !sent().includes('details[editions][0][editor]=aaaaaaaa00000001'), sent());
  check('no name is left with a placeholder', !sent().some((s) => s.includes('__INDEX')));

  // A search that fails says so.
  window.fetch = () => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve([]) });
  await type(author, 'x');
  check('a search that does not answer is said, not silent', /did not answer/.test(author.querySelector('[data-rel-results]').textContent), author.querySelector('[data-rel-results]').textContent);

  console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
  process.exit(failed === 0 ? 0 : 1);
})();
