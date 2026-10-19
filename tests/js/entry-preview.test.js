/*
 * The live preview of the content editor, run as shipped in jsdom: admin-preview.js in its "entry" mode (what is sent, when, and what
 * the frame does with the answer) and admin-entry-preview.js (the toggle, the remembered choices, the width, the block-to-block
 * clicks). The server is a stub; the real endpoint is covered by tests/http/entry_preview_test.py.
 *
 *   node tests/js/entry-preview.test.js        (after: npm ci --prefix tests/js)
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

const PAGE = `<!doctype html><body>
<div data-entry-split>
  <form id="entry-form">
    <input type="hidden" name="_csrf" value="tok">
    <input type="text" name="title" value="Hello">
    <input type="file" name="main_image_upload">
    <button type="button" data-entry-preview-toggle aria-expanded="false" aria-controls="entry-preview">Preview</button>
    <button type="button" data-tab="blocks">Blocks</button>
    <div data-block-editor>
      <section data-block-card="1"><button type="button" class="bk-toggle" aria-expanded="false">One</button></section>
      <section data-block-card="2"><button type="button" class="bk-toggle" aria-expanded="false">Two</button></section>
    </div>
  </form>
  <div id="entry-preview" data-entry-preview data-preview data-mode="entry" data-form="#entry-form" data-endpoint="/admin/edit?preview=entry" hidden>
    <button type="button" data-entry-preview-grip></button>
    <button type="button" data-entry-preview-close>Close</button>
    <aside class="bp-preview">
      <input type="radio" name="bp_size" value="1280" checked data-bp-size>
      <input type="radio" name="bp_mode" value="" checked data-bp-mode>
      <button type="button" data-bp-reload>Reload</button>
      <div data-bp-stage><iframe data-bp-frame title="Preview"></iframe></div>
    </aside>
  </div>
</div>`;

function boot(storage) {
  const dom = new JSDOM(PAGE, { runScripts: 'outside-only', url: 'http://localhost/admin/edit' });
  const w = dom.window;
  const calls = [];
  let answer = { html: '<p>drawn</p>', path: '/about' };
  w.fetch = (url, options) => {
    calls.push({ url, body: options.body });
    return Promise.resolve({ ok: true, json: () => Promise.resolve(answer) });
  };
  w.matchMedia = () => ({ matches: false, addEventListener() {} });
  Object.keys(storage || {}).forEach((key) => w.localStorage.setItem(key, storage[key]));
  w.eval(js('admin-preview.js'));
  w.eval(js('admin-entry-preview.js'));
  return { w, calls, d: w.document, answer: (value) => { answer = value; } };
}

(async () => {
  // ---- hidden: nothing is asked of the server
  let t = boot();
  const toggle = t.d.querySelector('[data-entry-preview-toggle]');
  const panel = t.d.querySelector('[data-entry-preview]');
  t.d.dispatchEvent(new t.w.CustomEvent('bk:change'));
  await wait(750);
  check('a hidden panel does not ask the server for a picture', t.calls.length === 0, t.calls.length);

  // ---- showing it draws at once and is remembered
  toggle.click();
  await wait(50);
  check('the toggle shows the panel and says so (aria-expanded)', !panel.hidden && toggle.getAttribute('aria-expanded') === 'true');
  check('showing it asks for a picture at once', t.calls.length === 1 && t.calls[0].url === '/admin/edit?preview=entry', t.calls.length);
  check('the choice is remembered', t.w.localStorage.getItem('faros.entryPreview.open') === '1');
  const sent = Array.from(t.calls[0].body.keys());
  check('the form is sent whole, but not the file field', sent.includes('title') && sent.includes('_csrf') && !sent.includes('main_image_upload'), sent);
  check('the frame shows the answer', t.d.querySelector('[data-bp-frame]').srcdoc === '<p>drawn</p>');

  // ---- debounce and events
  t.calls.length = 0;
  for (let i = 0; i < 4; i++) { t.d.dispatchEvent(new t.w.CustomEvent('bk:change')); await wait(100); }
  check('changes of the block editor wait for 600ms of quiet', t.calls.length === 0, t.calls.length);
  await wait(650);
  check('and then ask once', t.calls.length === 1, t.calls.length);
  t.calls.length = 0;
  const title = t.d.querySelector('[name=title]');
  title.value = 'Changed';
  title.dispatchEvent(new t.w.Event('input', { bubbles: true }));
  title.dispatchEvent(new t.w.Event('input', { bubbles: true }));
  await wait(300);
  check('typing in the form is waited for too', t.calls.length === 0);
  await wait(450);
  check('a field changed in the form asks once with the new value', t.calls.length === 1 && t.calls[0].body.get('title') === 'Changed', t.calls.length);

  // ---- a failed answer leaves the picture
  t.answer({ error: 'The front matter cannot be read' });
  t.d.dispatchEvent(new t.w.CustomEvent('bk:change'));
  await wait(800);
  check('an answer with an error leaves the last picture', t.d.querySelector('[data-bp-frame]').srcdoc === '<p>drawn</p>');

  // ---- hiding is remembered; a remembered choice opens it next time
  toggle.click();
  check('the toggle hides it again and remembers', panel.hidden && toggle.getAttribute('aria-expanded') === 'false' && t.w.localStorage.getItem('faros.entryPreview.open') === '0');
  t = boot({ 'faros.entryPreview.open': '1', 'faros.entryPreview.width': '640' });
  await wait(50);
  check('a remembered "open" shows the panel when the page loads, at the remembered width', !t.d.querySelector('[data-entry-preview]').hidden && t.d.querySelector('[data-entry-split]').style.getPropertyValue('--ep-width') === '640px');

  // ---- width with the keyboard
  const grip = t.d.querySelector('[data-entry-preview-grip]');
  grip.dispatchEvent(new t.w.KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
  check('the grip widens the panel with the left arrow and remembers it', t.d.querySelector('[data-entry-split]').style.getPropertyValue('--ep-width') === '664px' && t.w.localStorage.getItem('faros.entryPreview.width') === '664');
  for (let i = 0; i < 30; i++) { grip.dispatchEvent(new t.w.KeyboardEvent('keydown', { key: 'ArrowLeft', shiftKey: true, bubbles: true })); }
  check('the width stops at its limit', t.d.querySelector('[data-entry-split]').style.getPropertyValue('--ep-width') === '900px');

  // ---- a block clicked in the picture opens it in the builder
  const doc = t.w.document.implementation.createHTMLDocument('p');
  doc.body.innerHTML = '<section data-bk-block="1"><a href="/x"><span id="in">Go</span></a></section><section data-bk-block="0"></section>';
  Object.defineProperty(t.d.querySelector('[data-bp-frame]'), 'contentDocument', { get: () => doc });
  t.d.querySelector('[data-entry-preview]').dispatchEvent(new t.w.CustomEvent('preview:drawn', { bubbles: true }));
  let opened = 0;
  t.d.querySelectorAll('.bk-toggle')[1].addEventListener('click', function () { opened++; this.setAttribute('aria-expanded', 'true'); });
  const click = new t.w.MouseEvent('click', { bubbles: true, cancelable: true });
  doc.getElementById('in').dispatchEvent(click);
  check('a click on a block in the picture opens the block of that place in the builder', opened === 1, opened);
  check('a link in the picture is not followed', click.defaultPrevented);
  // the builder of today answers through its API (window.GnozioBlocks), which opens the block's settings in its panel
  const asked = [];
  t.w.GnozioBlocks = { open: (path) => { asked.push(path); return true; } };
  doc.querySelector('[data-bk-block="0"]').dispatchEvent(new t.w.MouseEvent('click', { bubbles: true, cancelable: true }));
  check('with the builder\'s API the place of the block is handed to it, not clicked', JSON.stringify(asked) === '["0"]' && opened === 1, JSON.stringify(asked));
  delete t.w.GnozioBlocks;
  // ---- and the other way: a block opened in the builder is outlined in the picture
  t.d.querySelectorAll('.bk-toggle')[0].click();
  check('a block header clicked in the builder outlines the block in the picture', doc.querySelector('[data-bk-block="0"]').classList.contains('faros-bk-hit'));

  console.log(failed ? 'FAILED: ' + failed : 'ALL PASSED');
  process.exit(failed ? 1 : 0);
})();
