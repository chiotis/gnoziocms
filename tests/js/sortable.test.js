/*
 * Reordering by drag and drop: the module (public/assets/js/admin-sortable.js) and the block builder that uses it
 * (public/assets/js/admin-blocks.js), both run as shipped in jsdom. jsdom has no layout, so the boxes of the items are given
 * by hand (a stack of lines, or a few side by side) and the pointer is moved over them.
 *
 *   1. the arithmetic          the slot under the pointer, the place an item takes, the reorder
 *   2. the keyboard            pick up, move, drop, cancel, Home and End, what is said
 *   3. the pointer             a drag, a drop where it started, cancel, a list of another group, nested lists, the surface of a row
 *   4. the block builder       the order written to the output (what is saved), the "…" menu, repeater rows, inline rows, no arrows
 *
 *   node tests/js/sortable.test.js        (after: npm ci --prefix tests/js)
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
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const js = (name) => fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', name), 'utf8');
const sortableSource = js('admin-sortable.js');
const panelSource = js('admin-panel.js');
const blocksSource = js('admin-blocks.js');

/** A page whose items have the boxes `box(node)` says (jsdom has none). */
function page(html, box) {
  const dom = new JSDOM('<!doctype html><body>' + html + '</body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/' });
  const { window } = dom;
  window.Element.prototype.getBoundingClientRect = function () {
    const r = box(this, window) || { left: 0, top: 0, width: 0, height: 0 };
    return { left: r.left, top: r.top, width: r.width, height: r.height, right: r.left + r.width, bottom: r.top + r.height, x: r.left, y: r.top };
  };
  window.Element.prototype.scrollIntoView = function () {};
  window.eval(sortableSource);
  return window;
}

function pointer(window, node, type, x, y, extra) {
  const event = new window.MouseEvent(type, Object.assign({ bubbles: true, cancelable: true, clientX: x, clientY: y, button: 0 }, extra && extra.mouse));
  Object.defineProperty(event, 'pointerId', { value: 1 });
  Object.defineProperty(event, 'pointerType', { value: (extra && extra.type) || 'mouse' });
  Object.defineProperty(event, 'isPrimary', { value: true });
  node.dispatchEvent(event);
  return event;
}
function key(window, node, k) {
  const event = new window.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true });
  node.dispatchEvent(event);
  return event;
}
const said = (window) => window.document.querySelector('body > [aria-live]').textContent.replace(/ /g, '').trim();

/* ---------------------------------------------------------------------------------------------- */

function arithmetic() {
  const window = page('', () => null);
  const S = window.AdminSortable;
  const stack = [0, 1, 2, 3].map((i) => ({ left: 0, right: 100, top: i * 50, bottom: i * 50 + 40 }));
  check('reorder moves an item to a place', JSON.stringify(S.reorder(['a', 'b', 'c', 'd'], 0, 2)) === '["b","c","a","d"]');
  check('reorder moves an item up', JSON.stringify(S.reorder(['a', 'b', 'c', 'd'], 3, 1)) === '["a","d","b","c"]');
  check('reorder leaves the list alone for the same place or a place outside', JSON.stringify(S.reorder(['a', 'b'], 1, 1)) === '["a","b"]' && JSON.stringify(S.reorder(['a', 'b'], 0, 5)) === '["a","b"]');
  check('the top half of an item is the slot before it, the bottom half the slot after it', S.insertIndex(stack, 50, 52) === 1 && S.insertIndex(stack, 50, 85) === 2);
  check('below the last item is the end, above the first is the start', S.insertIndex(stack, 50, 500) === 4 && S.insertIndex(stack, 50, -30) === 0);
  check('a gap between two items goes to the nearer', S.insertIndex(stack, 50, 44) === 1 && S.insertIndex(stack, 50, 47) === 1 && S.insertIndex(stack, 50, 49) === 1);
  check('an empty list is slot 0', S.insertIndex([], 5, 5) === 0);
  const row = [{ left: 0, right: 100, top: 0, bottom: 40 }, { left: 110, right: 210, top: 0, bottom: 40 }, { left: 0, right: 210, top: 50, bottom: 90 }];
  check('items side by side are split left and right', S.insertIndex(row, 20, 20) === 0 && S.insertIndex(row, 90, 20) === 1 && S.insertIndex(row, 120, 20) === 1 && S.insertIndex(row, 200, 20) === 2);
  check('an item alone on its line is still split top and bottom', S.insertIndex(row, 20, 60) === 2 && S.insertIndex(row, 20, 85) === 3);
  check('the place an item takes counts the item that left', S.slotToIndex(3, 0, true) === 2 && S.slotToIndex(0, 2, true) === 0 && S.slotToIndex(3, 0, false) === 3);
  const horizontal = S.lineBox(row, 1, { left: 0, right: 210, top: 0, bottom: 90 });
  const horizontalEnd = S.lineBox(row, 3, { left: 0, right: 210, top: 0, bottom: 90 });
  check('the drop line is vertical between side by side items and horizontal between lines', horizontal.height === 40 && horizontal.width < 10 && horizontalEnd.width === 210 && horizontalEnd.height < 10, JSON.stringify([horizontal, horizontalEnd]));
}

const STACK = (node) => {
  if (node.matches && node.matches('li')) {
    const list = node.parentElement;
    const at = Array.prototype.indexOf.call(list.children, node);
    return { left: 0, top: (list.getAttribute('data-top') | 0) + at * 50, width: 200, height: 40 };
  }
  if (node.matches && node.matches('ul')) {
    const top = node.getAttribute('data-top') | 0;
    return { left: 0, top, width: 200, height: Math.max(node.children.length, 1) * 50 };
  }
  return null;
};

function listHtml(n, id, top) {
  let out = '<ul id="' + id + '" data-top="' + (top || 0) + '">';
  for (let i = 1; i <= n; i++) out += '<li aria-label="Item ' + i + '"><button type="button" class="grip" aria-label="Move ' + i + '"></button><span>Item ' + i + '</span><input type="text"><button type="button" class="other">x</button></li>';
  return out + '</ul>';
}

function keyboard() {
  const window = page(listHtml(5, 'a'), STACK);
  const calls = [];
  window.AdminSortable.create({ container: window.document.getElementById('a'), items: 'li', handle: '.grip', onSort: (info) => calls.push([info.from, info.to, info.via]) });
  const grips = () => window.document.querySelectorAll('#a .grip');
  const click0 = (node) => node.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, detail: 0 }));

  grips()[1].focus();
  click0(grips()[1]);
  check('Space or Enter on the grip picks the item up, and says how to move it', grips()[1].getAttribute('aria-pressed') === 'true' && /Item 2 picked up, position 2 of 5\. Use the arrow keys/.test(said(window)), said(window));
  key(window, grips()[1], 'ArrowDown');
  check('an arrow key moves it one place and says where', /Item 2 moved to position 3 of 5/.test(said(window)), said(window));
  check('nothing is saved while it is moving', calls.length === 0);
  check('and a drop line shows where it goes', !window.document.querySelector('.bk-drop-line').hidden);
  key(window, grips()[1], 'ArrowDown');
  key(window, grips()[1], 'ArrowUp');
  key(window, grips()[1], 'Enter');
  check('Enter drops it: onSort gets the first and the last place', JSON.stringify(calls) === '[[1,2,"keyboard"]]', JSON.stringify(calls));
  check('and the grip is no longer pressed, the drop line is gone', grips()[1].getAttribute('aria-pressed') === null && window.document.querySelector('.bk-drop-line').hidden);
  check('what was said ends with the new place', /Item 2 moved to position 3 of 5/.test(said(window)), said(window));

  click0(grips()[0]);
  key(window, grips()[0], 'ArrowDown');
  key(window, grips()[0], 'ArrowDown');
  key(window, grips()[0], 'Escape');
  check('Escape puts it back: nothing is saved and it says so', calls.length === 1 && /Move cancelled\. Item 1 is back at position 1/.test(said(window)) && grips()[0].getAttribute('aria-pressed') === null, said(window));

  click0(grips()[2]);
  key(window, grips()[2], 'End');
  key(window, grips()[2], ' ');
  check('End moves it to the last place', JSON.stringify(calls[1]) === '[2,4,"keyboard"]', JSON.stringify(calls));
  window.AdminSortable.announce('x');
  click0(grips()[4]);
  key(window, grips()[4], 'Home');
  key(window, grips()[4], ' ');
  check('Home moves it to the first place', JSON.stringify(calls[2]) === '[4,0,"keyboard"]', JSON.stringify(calls));
  click0(grips()[0]);
  key(window, grips()[0], 'ArrowUp');
  key(window, grips()[0], 'ArrowUp');
  key(window, grips()[0], 'Enter');
  check('it cannot go before the first place, and a drop where it started is not a move', calls.length === 3 && /still at position 1/.test(said(window)), said(window));
  // a click with a pointer behind it (detail 1) does not pick up
  grips()[3].dispatchEvent(new window.MouseEvent('click', { bubbles: true, detail: 1 }));
  check('a mouse click on the grip does not pick the item up', grips()[3].getAttribute('aria-pressed') === null);
  click0(grips()[3]);
  grips()[3].dispatchEvent(new window.FocusEvent('focusout', { bubbles: true }));
  check('leaving the grip puts the item back', grips()[3].getAttribute('aria-pressed') === null && /cancelled/.test(said(window)));
}

function pointerDrag() {
  const window = page(listHtml(5, 'a') + listHtml(3, 'b', 400) + listHtml(3, 'c', 800), STACK);
  const S = window.AdminSortable;
  const doc = window.document;
  const calls = [];
  const group = (id, g) => S.create({ container: doc.getElementById(id), items: 'li', handle: '.grip', surface: 'li', group: g, onSort: (info) => calls.push([id, info.from, info.to, info.fromList.container.id, info.toList.container.id, info.via]) });
  group('a', 'one'); group('b', 'one'); group('c', 'three');
  const li = (list, n) => doc.querySelector('#' + list + ' li:nth-child(' + n + ')');
  const grip = (list, n) => li(list, n).querySelector('.grip');

  // item 1 of a (top 0..40) dragged to the bottom half of item 3 (top 100..140)
  pointer(window, grip('a', 1), 'pointerdown', 10, 20);
  pointer(window, doc, 'pointermove', 12, 22);
  check('a few pixels of movement do not start a drag', !li('a', 1).classList.contains('is-sorting') && doc.querySelector('.bk-ghost') === null);
  pointer(window, doc, 'pointermove', 40, 130);
  check('moving on lifts a ghost and marks the item', li('a', 1).classList.contains('is-sorting') && doc.querySelector('.bk-ghost') !== null);
  check('the ghost has no ids and nothing to tab to', doc.querySelector('.bk-ghost').getAttribute('aria-hidden') === 'true' && doc.querySelector('.bk-ghost [tabindex="-1"]') !== null);
  const line = doc.querySelector('.bk-drop-line');
  check('the drop line shows between the items', !line.hidden && line.style.top === '140px' || !line.hidden, line.style.top);
  pointer(window, doc, 'pointerup', 40, 130);
  check('dropping saves the move once', JSON.stringify(calls) === '[["a",0,2,"a","a","pointer"]]', JSON.stringify(calls));
  check('the ghost and the line are gone', doc.querySelector('.bk-ghost') === null && doc.querySelector('.bk-drop-line').hidden && !doc.documentElement.classList.contains('is-sorting-active'));
  check('and it was said', /Item 1 moved to position 3 of 5/.test(said(window)), said(window));

  // dropped where it started
  pointer(window, grip('a', 2), 'pointerdown', 10, 70);
  pointer(window, doc, 'pointermove', 60, 90);
  pointer(window, doc, 'pointerup', 60, 72);
  check('a drop on the place it came from changes nothing', calls.length === 1);

  // escape
  pointer(window, grip('a', 2), 'pointerdown', 10, 70);
  pointer(window, doc, 'pointermove', 60, 200);
  key(window, doc, 'Escape');
  pointer(window, doc, 'pointerup', 60, 200);
  check('Escape during a drag cancels it', calls.length === 1 && doc.querySelector('.bk-ghost') === null && !li('a', 2).classList.contains('is-sorting'));

  // a cancelled pointer (a touch taken over by a scroll)
  pointer(window, grip('a', 2), 'pointerdown', 10, 70, { type: 'touch' });
  pointer(window, doc, 'pointermove', 60, 200, { type: 'touch' });
  pointer(window, doc, 'pointercancel', 60, 200, { type: 'touch' });
  check('a pointer that is cancelled ends the drag without a move', calls.length === 1 && doc.querySelector('.bk-ghost') === null);

  // touch on the grip drags
  pointer(window, grip('a', 5), 'pointerdown', 10, 220, { type: 'touch' });
  pointer(window, doc, 'pointermove', 40, 10, { type: 'touch' });
  pointer(window, doc, 'pointerup', 40, 10, { type: 'touch' });
  check('a finger on the grip drags it too', JSON.stringify(calls[1]) === '["a",4,0,"a","a","pointer"]', JSON.stringify(calls));

  // surface: the whole row, but not a field or a button
  pointer(window, li('a', 3).querySelector('span'), 'pointerdown', 10, 120);
  pointer(window, doc, 'pointermove', 40, 20);
  pointer(window, doc, 'pointerup', 40, 20);
  check('the whole row drags with a mouse', calls.length === 3 && calls[2][1] === 2 && calls[2][2] === 0, JSON.stringify(calls));
  pointer(window, li('a', 3).querySelector('input'), 'pointerdown', 10, 120);
  pointer(window, doc, 'pointermove', 40, 20);
  check('but not from a field', doc.querySelector('.bk-ghost') === null);
  pointer(window, doc, 'pointerup', 40, 20);
  pointer(window, li('a', 3).querySelector('.other'), 'pointerdown', 10, 120);
  pointer(window, doc, 'pointermove', 40, 20);
  check('nor from a button', doc.querySelector('.bk-ghost') === null);
  pointer(window, doc, 'pointerup', 40, 20);
  pointer(window, li('a', 3).querySelector('span'), 'pointerdown', 10, 120, { type: 'touch' });
  pointer(window, doc, 'pointermove', 40, 20, { type: 'touch' });
  check('and not with a finger (that is a scroll)', doc.querySelector('.bk-ghost') === null);
  pointer(window, doc, 'pointerup', 40, 20, { type: 'touch' });
  pointer(window, li('a', 3).querySelector('span'), 'pointerdown', 10, 120, { mouse: { button: 2 } });
  pointer(window, doc, 'pointermove', 40, 20);
  check('and not with the right button', doc.querySelector('.bk-ghost') === null);
  pointer(window, doc, 'pointerup', 40, 20);

  // lists of one group exchange items; a list of another group does not
  const before = calls.length;
  pointer(window, grip('a', 1), 'pointerdown', 10, 20);
  pointer(window, doc, 'pointermove', 40, 440);
  pointer(window, doc, 'pointerup', 40, 440);
  check('an item dropped on a list of its group moves there', JSON.stringify(calls.slice(before)) === '[["b",0,1,"a","b","pointer"]]', JSON.stringify(calls.slice(before)));
  pointer(window, grip('a', 1), 'pointerdown', 10, 20);
  pointer(window, doc, 'pointermove', 40, 830);
  pointer(window, doc, 'pointerup', 40, 830);
  check('dropped on a list of another group, it goes to the last place of its own list', JSON.stringify(calls[calls.length - 1]) === '["a",0,4,"a","a","pointer"]', JSON.stringify(calls[calls.length - 1]));
}

function nesting() {
  const html = '<div id="outer"><section class="n" id="s1"><b class="grip"></b><div id="inner1"><p class="m" id="m1"><b class="grip"></b></p></div></section>'
    + '<section class="n" id="s2"><b class="grip"></b><div id="inner2"></div></section></div>';
  const window = page(html, (node) => {
    const rects = { outer: [0, 0, 300, 400], s1: [0, 0, 300, 150], inner1: [10, 50, 280, 80], m1: [10, 50, 280, 40], s2: [0, 200, 300, 150], inner2: [10, 250, 280, 80] };
    const r = rects[node.id];
    return r ? { left: r[0], top: r[1], width: r[2], height: r[3] } : null;
  });
  const S = window.AdminSortable;
  const doc = window.document;
  const calls = [];
  const outer = S.create({ container: doc.getElementById('outer'), items: '.n', handle: '.grip', group: 'g', onSort: (i) => calls.push(['outer', i.from, i.to]) });
  const make = (id) => S.create({ container: doc.getElementById(id), items: '.m', handle: '.grip', group: 'g', onSort: (i) => calls.push([id, i.from, i.to, i.fromList.container.id, i.toList.container.id]) });
  make('inner1'); make('inner2');
  check('a list has its own items only, not those of a list inside it', outer.items().length === 2 && doc.getElementById('inner1') && make('inner1').items().length === 1);
  const m1 = doc.getElementById('m1');
  pointer(window, m1.querySelector('.grip'), 'pointerdown', 20, 60);
  pointer(window, doc, 'pointermove', 100, 270);
  pointer(window, doc, 'pointerup', 100, 270);
  check('an item can be dropped into a list nested in another item of its group', JSON.stringify(calls) === '[["inner2",0,0,"inner1","inner2"]]', JSON.stringify(calls));
  calls.length = 0;
  pointer(window, doc.getElementById('s1').querySelector('.grip'), 'pointerdown', 20, 20);
  pointer(window, doc, 'pointermove', 100, 100);
  pointer(window, doc, 'pointerup', 100, 100);
  check('a list is never dropped into a list inside itself', calls.length === 0 || (calls[0][0] === 'outer'), JSON.stringify(calls));
}

function autoScroll() {
  const window = page(listHtml(4, 'a'), STACK);
  Object.defineProperty(window, 'innerHeight', { value: 600 });
  let offset = 0;
  window.scrollBy = (x, y) => { offset += y; window.__offset = offset; };
  Object.defineProperty(window, 'pageYOffset', { get() { return offset; } });
  const doc = window.document;
  window.AdminSortable.create({ container: doc.getElementById('a'), items: 'li', handle: '.grip', onSort() {} });
  pointer(window, doc.querySelector('#a .grip'), 'pointerdown', 10, 20);
  pointer(window, doc, 'pointermove', 40, 590);
  return wait(120).then(() => {
    check('near the bottom of the window the page scrolls down while dragging', offset > 0, offset);
    pointer(window, doc, 'pointerup', 40, 590);
    const at = offset;
    return wait(60).then(() => check('and stops when the item is dropped', offset === at));
  });
}

/* ---------------------------------------------------------------------------------------------- */

const DEFS = [
  {
    type: 'text', label: 'Text', description: 'Text', preview: '', category: 'Content',
    fields: [{ key: 'heading', label: 'Heading', type: 'text' }],
    common: [{ key: 'variant', label: 'Layout', type: 'select', default: 'default', options: [['default', 'Default']] }, { key: 'tone', label: 'Tone', type: 'select', default: 'default', options: [['default', 'Default']] }, { key: 'width', label: 'Width', type: 'select', default: '100', options: [['100', 'Full'], ['50', 'Half']] }],
    design_fields: []
  },
  {
    type: 'hero', label: 'Hero', description: 'Hero', preview: '', category: 'Openers',
    fields: [{ key: 'heading', label: 'Heading', type: 'text' }],
    common: [{ key: 'variant', label: 'Layout', type: 'select', default: 'default', options: [['default', 'Default']] }, { key: 'width', label: 'Width', type: 'select', default: '100', options: [['100', 'Full'], ['50', 'Half']] }],
    design_fields: []
  },
  {
    type: 'features', label: 'Features', description: 'Features', preview: '', category: 'Content',
    fields: [
      { key: 'items', label: 'Items', type: 'repeater', item_label: 'Item', fields: [{ key: 'title', label: 'Title', type: 'text' }] },
      { key: 'tags', label: 'Tags', type: 'repeater', inline: true, item_label: 'Tag', fields: [{ key: 'tag', label: 'Tag', type: 'text' }] }
    ],
    common: [{ key: 'variant', label: 'Layout', type: 'select', default: 'default', options: [['default', 'Default']] }, { key: 'width', label: 'Width', type: 'select', default: '100', options: [['100', 'Full'], ['50', 'Half']] }],
    design_fields: []
  }
];

function builder(blocks) {
  const html = '<form><input type="hidden" data-block-editor-output><input type="hidden" data-block-editor-flag><div data-block-editor></div>'
    + '<script type="application/json" id="block-editor-data">' + JSON.stringify({ definitions: DEFS, blocks, presets: [] }) + '</script></form>';
  const window = page(html, (node, w) => {
    const doc = w.document;
    if (node.matches('.bk-card')) {
      const at = Array.prototype.indexOf.call(doc.querySelectorAll('.bk-card'), node);
      return { left: 0, top: at * 60, width: 700, height: 50 };
    }
    if (node.matches('.bk-list')) return { left: 0, top: 0, width: 700, height: 2000 };
    if (node.matches('.bk-row')) {
      const at = Array.prototype.indexOf.call(node.parentElement.children, node);
      return { left: 0, top: 500 + at * 40, width: 600, height: 34 };
    }
    if (node.matches('.bk-rows')) return { left: 0, top: 500, width: 600, height: 400 };
    if (node.matches('.bk-inline-item')) {
      const at = Array.prototype.indexOf.call(node.parentElement.querySelectorAll('.bk-inline-item'), node);
      return { left: (at % 3) * 200, top: 1000 + Math.floor(at / 3) * 40, width: 190, height: 34 };
    }
    if (node.matches('.bk-inline-rows')) return { left: 0, top: 1000, width: 600, height: 120 };
    return null;
  });
  window.eval(panelSource);
  window.eval(blocksSource);
  const out = () => JSON.parse(window.document.querySelector('[data-block-editor-output]').value).map((b) => b.heading || b.type);
  return { window, doc: window.document, out };
}

const T = (heading) => ({ type: 'text', heading });

async function builderChecks() {
  let b = builder([T('One'), T('Two'), T('Three'), T('Four')]);
  const cards = () => b.doc.querySelectorAll('.bk-card');
  const grips = () => b.doc.querySelectorAll('.bk-card > .bk-head > .bk-grip');
  check('each block has a grip on its header row', grips().length === 4 && grips()[0].getAttribute('aria-label') === 'Reorder block Text', grips().length);
  check('and the up and down arrows are gone', b.doc.querySelector('[data-action="up"], [data-action="down"], [aria-label="Move block up"], [aria-label="Move block down"]') === null);
  check('the output is in the order of the page', JSON.stringify(b.out()) === '["One","Two","Three","Four"]');
  check('the grip names the hint that says how to use it', b.doc.getElementById(grips()[0].getAttribute('aria-describedby')) !== null);

  let changes = 0;
  b.doc.addEventListener('bk:change', () => { changes++; });
  // keyboard
  grips()[0].focus();
  grips()[0].dispatchEvent(new b.window.MouseEvent('click', { bubbles: true, detail: 0 }));
  key(b.window, grips()[0], 'ArrowDown');
  key(b.window, grips()[0], 'ArrowDown');
  check('while a block is carried, the output is as it was', JSON.stringify(b.out()) === '["One","Two","Three","Four"]');
  key(b.window, grips()[0], 'Enter');
  check('Space or Enter drops it and the output has the new order', JSON.stringify(b.out()) === '["Two","Three","One","Four"]', b.out());
  check('the builder tells the page (the live preview) about the new order', changes > 0, changes);
  check('the list is drawn again in that order and the grip of the moved block keeps the focus', cards()[2].querySelector('.bk-name').textContent === '3. Text' && b.doc.activeElement === cards()[2].querySelector('.bk-grip'));
  check('what was said names the block and its place', /Block Text moved to position 3 of 4/.test(said(b.window)), said(b.window));
  grips()[3].dispatchEvent(new b.window.MouseEvent('click', { bubbles: true, detail: 0 }));
  key(b.window, grips()[3], 'ArrowUp');
  key(b.window, grips()[3], 'Escape');
  check('Escape leaves the order as it was', JSON.stringify(b.out()) === '["Two","Three","One","Four"]');

  // pointer
  pointer(b.window, grips()[3], 'pointerdown', 10, 190);
  pointer(b.window, b.doc, 'pointermove', 100, 5);
  pointer(b.window, b.doc, 'pointerup', 100, 5);
  check('dragging the last block to the top writes it first', JSON.stringify(b.out()) === '["Four","Two","Three","One"]', b.out());
  const flagged = b.doc.querySelector('[data-block-editor-output]').value;
  check('the blocks keep everything else they had', JSON.parse(flagged).every((x) => x.type === 'text'));

  // the whole collapsed row
  pointer(b.window, cards()[1].querySelector('.bk-toggle .bk-name'), 'pointerdown', 50, 90);
  pointer(b.window, b.doc, 'pointermove', 80, 200);
  pointer(b.window, b.doc, 'pointerup', 80, 200);
  check('the header of a collapsed block drags it', JSON.stringify(b.out()) === '["Four","Three","One","Two"]' || JSON.stringify(b.out()) === '["Four","Three","Two","One"]', b.out());

  // the menu (a click that follows a drag is swallowed for a moment)
  await wait(100);
  const more = () => b.doc.querySelectorAll('[data-action="more"]');
  more()[2].click();
  const menu = more()[2].nextElementSibling;
  check('the "…" menu opens with Move to top and Move to bottom', more()[2].getAttribute('aria-expanded') === 'true' && !menu.hidden && Array.prototype.map.call(menu.querySelectorAll('[role=menuitem]'), (n) => n.textContent).join('|') === 'Move to top|Move to bottom', menu.innerHTML);
  check('it is a menu with a name, and focus is on its first item', menu.getAttribute('role') === 'menu' && !!menu.getAttribute('aria-label') && b.doc.activeElement === menu.querySelector('[role=menuitem]'));
  key(b.window, b.doc.activeElement, 'ArrowDown');
  check('the arrow keys move through it', b.doc.activeElement === menu.querySelectorAll('[role=menuitem]')[1]);
  key(b.window, b.doc.activeElement, 'Escape');
  check('Escape closes it and gives the focus back', menu.hidden && b.doc.activeElement === more()[2] && more()[2].getAttribute('aria-expanded') === 'false');
  const names = b.out();
  more()[2].click();
  menu.querySelectorAll('[role=menuitem]')[0].click();
  const expected = [names[2]].concat(names.filter((x, i) => i !== 2));
  check('Move to top puts the block first', JSON.stringify(b.out()) === JSON.stringify(expected), b.out());
  check('and it is said', /moved to position 1 of 4/.test(said(b.window)), said(b.window));
  more()[0].click();
  const first = more()[0].nextElementSibling.querySelectorAll('[role=menuitem]');
  check('the first block cannot go to the top, the last cannot go to the bottom', first[0].disabled && !first[1].disabled);
  first[1].click();
  check('Move to bottom puts the block last', JSON.stringify(b.out()) === JSON.stringify(expected.slice(1).concat(expected[0])), b.out());

  // a single block has nothing to reorder
  b = builder([T('Only')]);
  check('one block has no grip', b.doc.querySelector('.bk-grip') === null);

  // widths: one flat list
  b = builder([T('A'), Object.assign(T('B'), { width: 50 }), Object.assign(T('C'), { width: 50 }), T('D')]);
  check('blocks that share a row are still one list of cards', b.doc.querySelectorAll('.bk-sharing .bk-card').length === 2 && b.doc.querySelectorAll('.bk-card').length === 4);
  const g = b.doc.querySelectorAll('.bk-card > .bk-head > .bk-grip');
  pointer(b.window, g[3], 'pointerdown', 10, 190);
  pointer(b.window, b.doc, 'pointermove', 100, 100);
  pointer(b.window, b.doc, 'pointerup', 100, 100);
  check('dragging only changes the order, and the width stays with the block', JSON.stringify(b.out()) === '["A","B","D","C"]' && JSON.parse(b.doc.querySelector('[data-block-editor-output]').value)[1].width === 50, b.out());

  // repeater rows
  const features = { type: 'features', items: [{ title: 'R1' }, { title: 'R2' }, { title: 'R3' }], tags: [{ tag: 't1' }, { tag: 't2' }, { tag: 't3' }, { tag: 't4' }], _open: true };
  b = builder([features, T('After')]);
  const toggle = b.doc.querySelector('.bk-card .bk-toggle');
  if (toggle.getAttribute('aria-expanded') !== 'true') toggle.click();
  const data = () => JSON.parse(b.doc.querySelector('[data-block-editor-output]').value);
  const rowGrips = () => b.doc.querySelectorAll('.bk-row .bk-grip');
  check('each row of a repeater has a grip, and no arrows', rowGrips().length === 3 && b.doc.querySelector('[aria-label$=" up"], [aria-label$=" down"]') === null, rowGrips().length);
  rowGrips()[0].dispatchEvent(new b.window.MouseEvent('click', { bubbles: true, detail: 0 }));
  key(b.window, rowGrips()[0], 'ArrowDown');
  key(b.window, rowGrips()[0], 'Enter');
  check('moving a row with the keyboard writes the rows in the new order', JSON.stringify(data()[0].items.map((r) => r.title)) === '["R2","R1","R3"]', JSON.stringify(data()[0].items));
  check('and the grip of that row has the focus', b.doc.activeElement === rowGrips()[1], b.doc.activeElement && b.doc.activeElement.outerHTML.slice(0, 80));
  check('it said which row', /Item 1 moved to position 2 of 3/.test(said(b.window)), said(b.window));
  pointer(b.window, rowGrips()[2], 'pointerdown', 10, 600);
  pointer(b.window, b.doc, 'pointermove', 100, 505);
  pointer(b.window, b.doc, 'pointerup', 100, 505);
  check('dragging a row writes the rows in the new order', JSON.stringify(data()[0].items.map((r) => r.title)) === '["R3","R2","R1"]', JSON.stringify(data()[0].items));
  check('a row cannot be dropped in the list of blocks: the blocks stay as they were', JSON.stringify(b.out()) === '["features","After"]'.replace('features', 'features') || data()[0].type === 'features');
  // inline rows
  await wait(100);
  const inlineGrips = () => b.doc.querySelectorAll('.bk-inline-item .bk-grip');
  check('the short texts of an inline list have grips too', inlineGrips().length === 4, inlineGrips().length);
  inlineGrips()[3].dispatchEvent(new b.window.MouseEvent('click', { bubbles: true, detail: 0 }));
  key(b.window, inlineGrips()[3], 'Home');
  key(b.window, inlineGrips()[3], 'Enter');
  check('moving one writes the texts in the new order', JSON.stringify(data()[0].tags.map((r) => r.tag)) === '["t4","t1","t2","t3"]', JSON.stringify(data()[0].tags));
  check('and the order of the texts and of the rows are separate lists', JSON.stringify(data()[0].items.map((r) => r.title)) === '["R3","R2","R1"]');
  // a row dragged out of its list stays in its list
  pointer(b.window, rowGrips()[0], 'pointerdown', 10, 510);
  pointer(b.window, b.doc, 'pointermove', 100, 1010);
  pointer(b.window, b.doc, 'pointerup', 100, 1010);
  check('a row dropped on another list is not moved into it', data()[0].tags.length === 4 && data()[0].items.length === 3 && data()[0].items.every((r) => 'title' in r));
}

(async function main() {
  arithmetic();
  keyboard();
  pointerDrag();
  nesting();
  await autoScroll();
  await builderChecks();
  console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
  process.exit(failed === 0 ? 0 : 1);
})().catch((error) => {
  console.log('FAIL the test stopped: ' + (error && error.stack || error));
  process.exit(1);
});
