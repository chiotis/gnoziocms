/*
 * The bar over a map of entries (themes/default/blocks/map/block.js, the whole file) in jsdom, before the map is loaded: a menu for each
 * taxonomy of the data, several terms of one taxonomy meaning "any of them" and of different taxonomies "all of them", the counts that
 * follow the other choices, a tree offered as groups, the kinds and Clear filters, the page's own form put away where the bar covers it,
 * and data without the taxonomies (the categories' menu, as before) or with none (no menus).
 *
 *   node tests/js/map-filters.test.js        (after: npm ci --prefix tests/js)
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

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'themes', 'default', 'blocks', 'map', 'block.js'), 'utf8');
const item = (id, type, tax, cats) => ({ id, type, kind: type, title: id, url: '/' + id, lat: 35, lng: 25, tax, cats: cats || [], facts: [], line: [] });
const data = {
  items: [
    item('points/a', 'points', { activity: ['walk', 'swim'], categories: ['beaches', 'nature'] }),
    item('points/b', 'points', { activity: ['walk'], categories: ['forests', 'nature'] }),
    item('points/c', 'points', { activity: ['walk'], categories: ['culture'] }),
    item('routes/d', 'routes', { activity: ['cycle'] }),
  ],
  types: [{ id: 'points', label: 'Points', count: 3 }, { id: 'routes', label: 'Routes', count: 1 }],
  cats: [],
  facets: [
    { name: 'activity', label: 'Activity <b>', tree: false, options: [{ id: 'cycle', label: 'Cycling', depth: 0, count: 1 }, { id: 'swim', label: 'Swimming', depth: 0, count: 1 }, { id: 'walk', label: 'Walking', depth: 0, count: 3 }] },
    { name: 'categories', label: 'Category', tree: true, options: [{ id: 'nature', label: 'Nature', depth: 0, count: 2 }, { id: 'beaches', label: 'Beaches', depth: 1, count: 1 }, { id: 'forests', label: 'Forests', depth: 1, count: 1 }, { id: 'culture', label: 'Culture', depth: 0, count: 1 }] },
  ],
  tiles: {}, cluster: true, current: '',
};
const legacy = { items: [item('points/x', 'points', undefined, ['one']), item('points/y', 'points', undefined, ['two'])], types: [{ id: 'points', label: 'Points', count: 2 }], cats: [{ slug: 'one', label: 'One', count: 1 }, { slug: 'two', label: 'Two', count: 1 }] };
const bare = { items: [item('points/p', 'points'), item('points/q', 'points')], types: [{ id: 'points', label: 'Points', count: 2 }], cats: [], facets: [] };
const json = (d) => JSON.stringify(d).replace(/</g, '\\u003c');
const map = (id, d) => `<div class="geo" id="${id}" data-geo data-geo-load-mode="click" data-label-clear="Clear" data-label-reset="Clear filters">
  <div class="geo-filters" data-geo-filters hidden></div><p data-geo-status></p>
  <div class="geo-canvas" data-geo-canvas><button type="button" data-geo-load>Show map</button></div>
  <ul>${d.items.map((i) => `<li data-geo-item="${i.id}">${i.title}</li>`).join('')}</ul>
  <script type="application/json" data-geo-data>${json(d)}</script></div>`;
const dom = new JSDOM(`<!doctype html><body>
  <form id="mixed" data-geo-form><label class="archive-filter" data-geo-covered="activity"><select><option value="" selected></option></select></label>
    <label class="archive-filter" id="kept" data-geo-covered="categories"><select><option value="">All</option><option value="culture" selected>Culture</option></select></label>
    <label class="archive-filter" id="other"><select><option value=""></option></select></label></form>
  <form id="covered" data-geo-form><label class="archive-filter" data-geo-covered="activity"><select><option value="" selected></option></select></label></form>
  ${map('main', data)}${map('legacy', legacy)}${map('bare', bare)}</body>`, { runScripts: 'outside-only' });
const { window } = dom;
const doc = window.document;

async function main() {
  window.eval(source);
  const deadline = Date.now() + 5000;
  while (doc.readyState === 'loading' && Date.now() < deadline) await new Promise((r) => setTimeout(r, 10));
  const root = doc.getElementById('main');
  const bar = root.querySelector('[data-geo-filters]');
  const facet = (name) => root.querySelector('[data-geo-facet="' + name + '"]');
  const box = (name, id) => facet(name).querySelector('input[value="' + id + '"]');
  const count = (name, id) => box(name, id).parentNode.querySelector('small').textContent;
  const click = (input) => { input.checked = !input.checked; input.dispatchEvent(new window.Event('change', { bubbles: true })); };
  const shown = () => Array.from(root.querySelectorAll('[data-geo-item]')).filter((li) => !li.hidden).map((li) => li.getAttribute('data-geo-item')).sort().join(' ');

  check('the bar is drawn when the page loads, before the map, with the kinds and a menu for each taxonomy', !bar.hidden && root.querySelectorAll('.geo-chip').length === 3 && root.querySelectorAll('.geo-facet').length === 2, bar.innerHTML);
  check('names are text, never markup', facet('activity').querySelector('summary span').textContent === 'Activity <b>' && !facet('activity').querySelector('summary b:not(.geo-facet-n)'));
  check('each term with how many entries it has', count('activity', 'walk') === '3' && count('categories', 'nature') === '2');

  click(box('activity', 'swim'));
  check('a term keeps the entries filed under it', shown() === 'points/a', shown());
  click(box('activity', 'cycle'));
  check('two terms of one taxonomy: either of them', shown() === 'points/a routes/d', shown());
  check('the badge says how many terms are chosen, and Clear filters appears', facet('activity').querySelector('.geo-facet-n').textContent === '2' && !root.querySelector('.geo-reset').hidden);
  check('a taxonomy\'s own counts do not follow its own choice', count('activity', 'walk') === '3' && count('activity', 'swim') === '1');
  check('the other taxonomies count what the choice leaves, and a term with nothing left cannot be chosen', count('categories', 'nature') === '1' && count('categories', 'forests') === '0' && box('categories', 'forests').disabled && !box('categories', 'beaches').disabled);
  click(box('categories', 'beaches'));
  check('terms of different taxonomies: all of them', shown() === 'points/a', shown());
  check('the status says how many are shown', root.querySelector('[data-geo-status]').textContent === '1 / 4');

  root.querySelector('.geo-reset').click();
  check('Clear filters takes every choice away', shown() === 'points/a points/b points/c routes/d' && !box('activity', 'swim').checked && root.querySelector('.geo-reset').hidden && facet('activity').querySelector('.geo-facet-n').hidden);
  click(box('categories', 'nature'));
  check('a term of a tree keeps what is filed under the terms below it', shown() === 'points/a points/b', shown());
  click(box('categories', 'culture'));
  check('and with another term of the tree, either', shown() === 'points/a points/b points/c', shown());
  const group = facet('categories').querySelector('.geo-opt-group');
  check('a tree is offered as groups: a term at the top with the terms under it', group && group.getAttribute('role') === 'group' && Array.from(group.querySelectorAll('input')).map((i) => i.value).join() === 'nature,beaches,forests' && !group.contains(box('categories', 'culture')) && group.querySelector('.is-head input').value === 'nature');
  facet('categories').querySelector('.geo-facet-clear').click();
  check('a menu\'s Clear takes its own choices away', shown() === 'points/a points/b points/c routes/d' && !box('categories', 'nature').checked);

  root.querySelector('.geo-chip[data-type="routes"]').click();
  check('a kind is chosen with the others, and the counts follow it', shown() === 'routes/d' && count('activity', 'cycle') === '1' && count('activity', 'walk') === '0');
  root.querySelector('.geo-chip[data-type=""]').click();

  facet('activity').open = true;
  facet('activity').dispatchEvent(new window.Event('toggle'));
  facet('categories').open = true;
  facet('categories').dispatchEvent(new window.Event('toggle'));
  check('one menu open at a time', !facet('activity').open && facet('categories').open);
  doc.body.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
  check('a click outside closes it', !facet('categories').open);

  check('the page\'s form puts away a field the bar covers', doc.querySelector('#mixed [data-geo-covered="activity"]').hidden);
  check('but not one that holds a choice (so it can be undone), nor one the bar does not cover', !doc.getElementById('kept').hidden && !doc.getElementById('other').hidden && !doc.getElementById('mixed').hidden);
  check('a form with nothing left in it is put away', doc.getElementById('covered').hidden);

  const old = doc.getElementById('legacy');
  check('data without the taxonomies: the categories as a menu, as before', old.querySelectorAll('.geo-facet').length === 0 && old.querySelectorAll('select.geo-select option').length === 3);
  const select = old.querySelector('select.geo-select');
  select.value = 'two';
  select.dispatchEvent(new window.Event('change'));
  check('and it filters', Array.from(old.querySelectorAll('[data-geo-item]')).filter((li) => !li.hidden).map((li) => li.getAttribute('data-geo-item')).join() === 'points/y');
  check('a type with no taxonomies: no menus, and with nothing else to choose no bar', doc.getElementById('bare').querySelector('[data-geo-filters]').hidden && !doc.getElementById('bare').querySelector('.geo-facet'));

  console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
  process.exit(failed === 0 ? 0 : 1);
}

main().catch((error) => { console.log('FAIL ' + (error && error.stack || error)); process.exit(1); });
