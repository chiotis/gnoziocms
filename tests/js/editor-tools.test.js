/*
 * The visual editor's toolbar, run as it is shipped (public/assets/js/admin-editor.js, the whole file) in jsdom: the picture dialog and
 * the library it opens, the alignment buttons, and what they leave in the Markdown. jsdom has no editing commands, so the two the
 * editor asks the browser for here (insertHTML and formatBlock) are recorded and done by hand.
 *
 *   node tests/js/editor-tools.test.js        (after: npm ci --prefix tests/js)
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

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'admin-editor.js'), 'utf8');

/** The server's drawing of the text, as the endpoint gives it. */
const DRAWN = {
  ok: true, tail: '', verbatim: true,
  blocks: [
    { html: '<p>Hello world</p>', source: 'Hello world' },
    { html: '<h2>Title</h2>', source: '## Title' },
    { html: '<ul>\n<li>item</li>\n</ul>', source: '- item' },
  ],
};

/** The same text with a picture of its own and one inside a link, for what is done with a picture. */
const WITH_PICTURES = {
  ok: true, tail: '', verbatim: true,
  blocks: DRAWN.blocks.concat([
    { html: '<p><img src="/uploads/media/a.png" alt="A cat"></p>', source: '![A cat](/uploads/media/a.png)' },
    { html: '<p>Words <img src="/uploads/media/b.png" alt="B"> in a line</p>', source: 'Words ![B](/uploads/media/b.png) in a line' },
    { html: '<p><a href="/x"><img src="/uploads/media/c.png" alt="C"></a></p>', source: '[![C](/uploads/media/c.png)](/x)' },
  ]),
};
const BASE_MD = 'Hello world\n\n## Title\n\n- item';

async function page(drawn) {
  const dom = new JSDOM('<!doctype html><body><form><input type="hidden" name="_csrf" value="t"><section>'
    + '<button type="button" data-ed-mode="visual" hidden>Visual</button><button type="button" data-ed-mode="markdown" hidden>Markdown</button>'
    + '<div data-editor data-endpoint="/admin/markdown-visual" data-raw-html="0"><div data-ed-tools hidden></div><div data-md-toolbar hidden></div>'
    + '<div data-ed-surface contenteditable="true" hidden></div><textarea name="body" data-md-body>Hello world\n\n## Title\n\n- item</textarea>'
    + '<span data-ed-words></span><span data-ed-note></span></div></section></form></body>', { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://localhost/' });
  const { window } = dom;
  const calls = [];
  // jsdom does not know contentEditable, which the editor looks for before it starts.
  Object.defineProperty(window.HTMLElement.prototype, 'contentEditable', { get() { return this.getAttribute('contenteditable') || 'inherit'; }, set(v) { this.setAttribute('contenteditable', v); }, configurable: true });
  window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(drawn || DRAWN) });
  window.document.execCommand = function (name, ui, value) { calls.push([name, value]); return true; };
  const library = { callback: null, open(callback) { this.callback = callback; const d = window.document.createElement('dialog'); d.setAttribute('open', ''); d.innerHTML = '<button type="button" data-pick>A picture</button>'; window.document.body.appendChild(d); this.dialog = d; } };
  window.FarosMediaPicker = library;
  window.eval(source);
  await wait(50);
  const $ = (selector) => window.document.querySelector(selector);
  const surface = $('[data-ed-surface]');
  const click = (node) => { node.dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true, cancelable: true })); node.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true })); };
  const caretIn = (node) => { const range = window.document.createRange(); range.selectNodeContents(node); range.collapse(true); const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range); };
  return { window, $, surface, click, caretIn, calls, library };
}

(async function main() {
  let t = await page();
  check('old and new editor APIs share the same editor state', t.window.GnozioEditor === t.window.FarosEditor && typeof t.window.GnozioEditor.markdownOf === 'function');
  check('the editor starts, with the toolbar and the text drawn', t.surface.children.length === 3 && t.$('[data-cmd=image]') !== null, t.surface.innerHTML);
  check('and four alignment buttons', ['alignleft', 'aligncenter', 'alignright', 'alignjustify'].every((c) => t.$('[data-cmd=' + c + ']') !== null));

  // ---- the picture dialog
  t.caretIn(t.surface.children[0]);
  t.click(t.$('[data-cmd=image]'));
  check('the picture button opens the dialog, with the library', t.$('.ed-pop') !== null && Array.from(t.window.document.querySelectorAll('.ed-pop button')).some((b) => /Library/.test(b.textContent)));
  Array.from(t.window.document.querySelectorAll('.ed-pop button')).find((b) => /Library/.test(b.textContent)).click();
  check('the library opens', t.library.dialog !== undefined);
  t.click(t.library.dialog.querySelector('[data-pick]'));
  check('a click in the library does not close the dialog it was opened from', t.$('.ed-pop') !== null);
  t.library.callback('/uploads/media/pic.png', { alt: 'A cat' });
  const inputs = t.window.document.querySelectorAll('.ed-pop input[type=text]');
  check('the picture chosen is in the dialog, with its description', inputs[0].value === '/uploads/media/pic.png' && inputs[1].value === 'A cat', [inputs[0].value, inputs[1].value]);
  t.$('.ed-pop').dispatchEvent(new t.window.Event('submit', { bubbles: true, cancelable: true }));
  const inserted = t.calls.find((c) => c[0] === 'insertHTML');
  check('Insert puts the picture in the text', inserted !== undefined && inserted[1] === '<img src="/uploads/media/pic.png" alt="A cat">', t.calls);
  check('and closes the dialog', t.$('.ed-pop') === null);
  t.click(t.$('[data-cmd=image]'));
  check('a click anywhere else closes it', (() => { t.click(t.window.document.body); return t.$('.ed-pop') === null; })());
  t.click(t.$('[data-cmd=image]'));
  t.click(t.$('.ed-pop input'));
  check('a click inside it does not', t.$('.ed-pop') !== null);

  // ---- alignment
  t = await page();
  const paragraph = t.surface.children[0];
  t.caretIn(paragraph);
  t.click(t.$('[data-cmd=aligncenter]'));
  check('a paragraph is centered', paragraph.getAttribute('align') === 'center', paragraph.outerHTML);
  check('and the button says so', t.$('[data-cmd=aligncenter]').getAttribute('aria-pressed') === 'true');
  await wait(400);
  check('the Markdown has it, in the line before the paragraph, and the other blocks as they were', t.$('textarea').value === '{align=center}\nHello world\n\n## Title\n\n- item', JSON.stringify(t.$('textarea').value));
  t.click(t.$('[data-cmd=alignright]'));
  check('another choice replaces it', paragraph.getAttribute('align') === 'right' && t.$('[data-cmd=aligncenter]').getAttribute('aria-pressed') === 'false', paragraph.outerHTML);
  t.click(t.$('[data-cmd=alignjustify]'));
  check('justified too', paragraph.getAttribute('align') === 'justify');
  t.click(t.$('[data-cmd=alignleft]'));
  await wait(400);
  check('left is the usual, and takes the choice away', !paragraph.hasAttribute('align') && t.$('textarea').value === 'Hello world\n\n## Title\n\n- item', JSON.stringify(t.$('textarea').value));

  const heading = t.surface.children[1];
  t.caretIn(heading);
  t.click(t.$('[data-cmd=aligncenter]'));
  await wait(400);
  check('a heading is aligned as a paragraph is', heading.getAttribute('align') === 'center' && t.$('textarea').value === 'Hello world\n\n{align=center}\n## Title\n\n- item', JSON.stringify(t.$('textarea').value));

  // a selection over several blocks aligns each of them
  const range = t.window.document.createRange();
  range.setStart(paragraph.firstChild, 2);
  range.setEnd(heading.firstChild, 2);
  const selection = t.window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
  t.click(t.$('[data-cmd=alignright]'));
  check('a selection over a paragraph and a heading aligns both', paragraph.getAttribute('align') === 'right' && heading.getAttribute('align') === 'right', [paragraph.outerHTML, heading.outerHTML]);

  // a list item is not aligned, and the person is told
  const item = t.surface.querySelector('li');
  t.caretIn(item);
  t.click(t.$('[data-cmd=aligncenter]'));
  check('a list item is left alone, and the person is told why', !item.hasAttribute('align') && !t.surface.querySelector('ul').hasAttribute('align') && /not for list items/.test(t.$('[data-ed-note]').textContent), t.$('[data-ed-note]').textContent);

  // ---- a picture in the text: picked, changed, removed
  t = await page(WITH_PICTURES);
  const lone = t.surface.querySelectorAll('img')[0];
  t.click(lone);
  check('a click on a picture picks it, and a bar offers to change or remove it', lone.classList.contains('is-picked') && t.$('.ed-picturebar') !== null && /Remove picture/.test(t.$('.ed-picturebar').textContent), t.surface.innerHTML);
  await wait(400);
  check('picking changes nothing in the text', t.$('textarea').value === BASE_MD, JSON.stringify(t.$('textarea').value));
  t.click(t.surface.children[0]);
  check('a click elsewhere un-picks it, and takes the bar away', !lone.classList.contains('is-picked') && t.$('.ed-picturebar') === null);
  t.click(lone);
  t.surface.dispatchEvent(new t.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
  check('so does Escape', !lone.classList.contains('is-picked') && t.$('.ed-picturebar') === null);

  t.click(lone);
  Array.from(t.window.document.querySelectorAll('.ed-picturebar button')).find((b) => /Remove/.test(b.textContent)).click();
  check('Remove picture takes the picture away, and the paragraph that held only it', t.surface.querySelectorAll('img').length === 2 && !Array.from(t.surface.children).some((c) => c.tagName === 'P' && c.textContent === '' && !c.querySelector('img')) && t.surface.children.length === 5, t.surface.innerHTML);
  check('and the bar', t.$('.ed-picturebar') === null);
  await wait(400);
  check('the Markdown has lost it, and nothing else', !t.$('textarea').value.includes('a.png') && t.$('textarea').value.includes('Words ![B](/uploads/media/b.png) in a line') && t.$('textarea').value.startsWith(BASE_MD), JSON.stringify(t.$('textarea').value));

  const inLine = t.surface.querySelectorAll('img')[0];
  t.click(inLine);
  t.surface.dispatchEvent(new t.window.KeyboardEvent('keydown', { key: 'Backspace', bubbles: true, cancelable: true }));
  check('Backspace removes a picked picture, and the words round it stay', t.surface.querySelectorAll('img').length === 1 && t.surface.textContent.includes('Words') && t.surface.textContent.includes('in a line'), t.surface.innerHTML);
  await wait(400);
  check('as Markdown', t.$('textarea').value.includes('Words  in a line') || /Words\s+in a line/.test(t.$('textarea').value), JSON.stringify(t.$('textarea').value));

  const linked = t.surface.querySelector('img');
  t.click(linked);
  t.surface.dispatchEvent(new t.window.KeyboardEvent('keydown', { key: 'Delete', bubbles: true, cancelable: true }));
  check('Delete does too, and a picture that was all a link held takes the link with it', t.surface.querySelectorAll('img, a').length === 0 && t.surface.children.length === 4, t.surface.innerHTML);
  t.caretIn(t.surface.children[0]);
  t.surface.dispatchEvent(new t.window.KeyboardEvent('keydown', { key: 'Backspace', bubbles: true, cancelable: true }));
  check('Backspace when nothing is picked is left to the browser', t.surface.children.length === 4);

  t = await page(WITH_PICTURES);
  const first = t.surface.querySelectorAll('img')[0];
  t.click(first);
  Array.from(t.window.document.querySelectorAll('.ed-picturebar button')).find((b) => /Edit/.test(b.textContent)).click();
  const edit = Array.from(t.window.document.querySelectorAll('.ed-pop input[type=text]'));
  check('Edit opens the address and the description as they are', t.$('.ed-pop') !== null && edit[0].value === '/uploads/media/a.png' && edit[1].value === 'A cat', edit.map((i) => i.value));
  edit[1].value = 'A black cat';
  t.$('.ed-pop').dispatchEvent(new t.window.Event('submit', { bubbles: true, cancelable: true }));
  check('Apply changes the picture', first.getAttribute('alt') === 'A black cat' && first.getAttribute('src') === '/uploads/media/a.png', first.outerHTML);
  await wait(400);
  check('and the Markdown', t.$('textarea').value.includes('![A black cat](/uploads/media/a.png)'), JSON.stringify(t.$('textarea').value));
  t.click(first);
  Array.from(t.window.document.querySelectorAll('.ed-picturebar button')).find((b) => /Edit/.test(b.textContent)).click();
  Array.from(t.window.document.querySelectorAll('.ed-pop button')).find((b) => /Remove picture/.test(b.textContent)).click();
  check('and the form has Remove picture as well', !t.surface.contains(first) && t.$('.ed-pop') === null);

  // beside the text
  t = await page(WITH_PICTURES);
  const beside = t.surface.querySelectorAll('img')[0];
  t.click(beside);
  const sides = Array.from(t.window.document.querySelectorAll('.ed-picturebar [data-side]'));
  check('a picked picture can be put in the text, on the left or on the right, and says which it is on', sides.map((b) => b.getAttribute('data-side')).join() === ',left,right' && sides[0].getAttribute('aria-pressed') === 'true' && sides[1].getAttribute('aria-pressed') === 'false', sides.map((b) => b.outerHTML));
  sides[1].click();
  check('Left floats it: the picture has align=left and the button is pressed', beside.getAttribute('align') === 'left' && t.window.document.querySelector('.ed-picturebar [data-side=left]').getAttribute('aria-pressed') === 'true' && beside.classList.contains('is-picked'), beside.outerHTML);
  await wait(400);
  check('and the Markdown says so', t.$('textarea').value.includes('![A cat](/uploads/media/a.png){align=left}') && t.$('textarea').value.includes('Words ![B](/uploads/media/b.png) in a line'), JSON.stringify(t.$('textarea').value));
  t.window.document.querySelector('.ed-picturebar [data-side=right]').click();
  await wait(400);
  check('Right moves it to the other side', beside.getAttribute('align') === 'right' && t.$('textarea').value.includes('![A cat](/uploads/media/a.png){align=right}'), JSON.stringify(t.$('textarea').value));
  t.window.document.querySelector('.ed-picturebar [data-side=""]').click();
  await wait(400);
  check('and In the text puts it back, with nothing left in the Markdown', !beside.hasAttribute('align') && t.$('textarea').value.includes('![A cat](/uploads/media/a.png)') && !t.$('textarea').value.includes('{align=right}'), JSON.stringify(t.$('textarea').value));

  // a picked picture does not make its block be written again
  t = await page(WITH_PICTURES);
  t.click(t.surface.querySelectorAll('img')[1]);
  t.caretIn(t.surface.children[0]);
  t.click(t.$('[data-cmd=aligncenter]'));
  await wait(400);
  check('a picture that is picked while something else is changed is written as it was', t.$('textarea').value === '{align=center}\nHello world\n\n## Title\n\n- item\n\n![A cat](/uploads/media/a.png)\n\nWords ![B](/uploads/media/b.png) in a line\n\n[![C](/uploads/media/c.png)](/x)', JSON.stringify(t.$('textarea').value));

  console.log(failed === 0 ? '\nALL PASSED' : '\n' + failed + ' FAILED');
  process.exit(failed === 0 ? 0 : 1);
})().catch((error) => {
  console.log('FAIL the test stopped: ' + (error && error.stack || error));
  process.exit(1);
});
